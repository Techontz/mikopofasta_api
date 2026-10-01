<?php

namespace App\Services;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Integrations\BankMandate\BankMandateGateway;
use App\Integrations\Sms\SmsGateway;
use App\Integrations\Vodacom\DisbursementCallback;
use App\Integrations\Vodacom\VodacomGateway;
use App\Models\ApprovalPolicy;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanAssessment;
use App\Models\LoanCategory;
use App\Models\LoanDisbursement;
use App\Models\LoanMandate;
use App\Models\LoanOffset;
use App\Models\LoanTransaction;
use App\Models\SmsLog;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Credit\CreditAssessment;
use App\Services\Customers\CustomerDebt;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Loan lifecycle state machine (Documents: 🏦 LOAN PROCESS OVERVIEW and the handwritten "Steps of building").
 *
 * APPLY (loan officer) → MANAGER (approve / reject / modify) → E-MANDATE + OTP when the product requires it →
 * CREDIT OFFICER (Vodacom KYC name/number verification, approve / reject / modify; reference number on approval)
 * → FINANCE (prepare batch) → VODACOM DISBURSEMENT (callback; retry max 3 with a new batch each, then ESCALATED
 * → cancel / suspense / other channel) → ACTIVE (ledger + schedules + SMS) → OVERDUE / DEFAULT → CLOSED → early-settlement freeze.
 *
 * Every transition is written to the audit trail (audit_logs, auditable = loan). No ledger entry is posted before a
 * disbursement succeeds; the posting itself is LoanService::withdraw().
 *
 * Disbursement source: Finance chooses where the money is paid from when preparing (and may change it before sending):
 * "cash" = the loan branch's PRINCIPAL A/C — the branch lending cash fund that the COMPANY ACCOUNT floats money into
 * (FloatService) and bank → branch transfers top up — or "bank" = a company bank account. The source is stored on the
 * batch, its balance is checked before money is sent, and on success the batch keeps the journal entry
 * (Dr LOAN RECEIVABLE / Cr source). A loan is posted once: batch and loan rows are locked and a loan that already has
 * a successful batch is never posted again (duplicate callbacks, retries and double clicks create no second entry).
 */
class LoanWorkflow
{
    public const AGREEMENT_MISSING = 'Upload the loan agreement filled and signed by the customer before approving';

    public const AGREEMENT_NOT_READY = 'The loan agreement is available after the branch manager approves the loan';

    public function __construct(
        private readonly LoanService $loans,
        private readonly CustomerEligibility $eligibility,
        private readonly VodacomGateway $vodacom,
        private readonly BankMandateGateway $bank,
        private readonly SmsGateway $sms,
    ) {}

    /**
     * Whether a customer may apply now. Two separate answers: `eligible` (category/KYC rules, one application at a
     * time, top-up conditions when a loan is still running) and `freeze` (re-borrowing freeze, see
     * CustomerEligibility::freeze()). `allowed` = eligible AND not frozen; `reasons` lists both.
     *
     * @return array{allowed: bool, eligible: bool, frozen: bool, reasons: list<string>, eligibility_reasons: list<string>, frozen_until: string|null, freeze: array<string, mixed>, topup: array{loan_id: int, loan_number: string, eligible: bool, paid_percent: float, required_percent: float, outstanding: float, reasons: list<string>}|null, debt: array<string, mixed>, rules: array<string, mixed>}
     */
    public function borrowingStatus(Customer $customer, ?int $loanCategoryId = null, ?float $amount = null): array
    {
        $reasons = $this->eligibility->violations($customer, $loanCategoryId, $amount);
        $rules = $this->eligibility->for($customer);
        $freeze = $rules['freeze'];

        if ($customer->loans()->status(...LoanStatus::inPipeline())->exists()) {
            $reasons[] = 'Customer already has a loan waiting for approval or withdrawal';
        }

        $running = $customer->loans()->status(...LoanStatus::repayable())->latest('id')->first();
        $topup = $running ? $this->topupEligibility($running) : null;
        if ($topup !== null && ! $topup['eligible']) {
            $reasons[] = 'NOT ELIGIBLE for top-up: '.implode(', ', $topup['reasons']);
        }

        // Debts carried over from the old system are the customer's debts like any other: a customer is never treated as
        // debt-free because the debt was imported. Its loan is held by the top-up rule above; its penalties and salary
        // advances must be cleared too before a new loan.
        $debt = app(CustomerDebt::class)->summary($customer);
        if ($debt['old_system']['penalty'] > 0.004) {
            $reasons[] = 'Old-system penalty outstanding: '.money($debt['old_system']['penalty']).' — clear it before a new loan';
        }
        if ($debt['old_system']['salary_advance'] > 0.004) {
            $reasons[] = 'Old-system salary advance outstanding: '.money($debt['old_system']['salary_advance']).' — clear it before a new loan';
        }

        $eligibilityReasons = array_values(array_unique($reasons));

        return [
            'allowed' => $eligibilityReasons === [] && ! $freeze['frozen'],
            'eligible' => $eligibilityReasons === [],
            'frozen' => $freeze['frozen'],
            'reasons' => $freeze['frozen'] ? [$freeze['message'], ...$eligibilityReasons] : $eligibilityReasons,
            'eligibility_reasons' => $eligibilityReasons,
            'frozen_until' => $freeze['frozen'] ? $freeze['frozen_until'] : null,
            'freeze' => $freeze,
            'topup' => $topup,
            'debt' => $debt,
            'rules' => $rules,
        ];
    }

    /**
     * @throws ValidationException when the amount is outside the loan category's limits (amount_from – amount_to)
     */
    private function assertWithinLimits(LoanCategory $category, float $amount, string $errorKey): void
    {
        if ($amount < (float) $category->amount_from || $amount > (float) $category->amount_to) {
            throw ValidationException::withMessages([$errorKey => "Loan amount must be between {$category->level_label}"]);
        }
    }

    /**
     * Top-up (Documents: "Paid certain % + No overdue → new loan allowed"). The required share is the
     * product's topup percent; the paid share is repayments over principal + interest + insurance.
     *
     * @return array{loan_id: int, loan_number: string, eligible: bool, paid_percent: float, required_percent: float, outstanding: float, reasons: list<string>}
     */
    public function topupEligibility(Loan $loan): array
    {
        $paidPercent = $this->loans->paidPercent($loan);
        $required = (float) ($loan->category?->topup_percent ?? 0);
        $reasons = [];

        // A loan carried over from the old system has none of this system's terms, so its required share is the company's
        // old-system top-up percent (Super Admin setting). At 100 % it must be cleared first.
        if ($loan->is_legacy_opening) {
            $required = (float) ($loan->company?->legacy_topup_percent ?? 100);
        }
        if ($loan->is_legacy_opening && $required >= 100) {
            return [
                'loan_id' => $loan->id,
                'loan_number' => $loan->loan_number,
                'eligible' => false,
                'paid_percent' => $paidPercent,
                'required_percent' => 100.0,
                'outstanding' => $this->loans->outstanding($loan)['total'],
                'reasons' => ['the old-system loan '.($loan->reference_number ?? $loan->loan_number).' still owes '.money($this->loans->outstanding($loan)['total']).' and must be cleared first'],
            ];
        }

        if ($loan->status !== LoanStatus::Active || (int) $loan->days_past_due > 0) {
            $reasons[] = 'the running loan is overdue';
        }
        if ($required <= 0) {
            $reasons[] = 'the product does not allow top-up';
        } elseif ($paidPercent < $required) {
            $reasons[] = "paid {$paidPercent}% of required {$required}%";
        }

        return [
            'loan_id' => $loan->id,
            'loan_number' => $loan->loan_number,
            'eligible' => $reasons === [],
            'paid_percent' => $paidPercent,
            'required_percent' => $required,
            'outstanding' => $this->loans->outstanding($loan)['total'],
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array{loan_category_id: int, group_id?: int|null, amount_applied: float, sessions: int, formula: string, fee_deduct: bool, reason: string}  $data
     */
    public function apply(Customer $customer, array $data, Employee $employee): Loan
    {
        // Customer → customer type → active loan category of that type, then the category's limits, then eligibility and freeze.
        $this->eligibility->assertLoanCategoryAvailable($customer, (int) $data['loan_category_id'], 'loan_category_id');
        $this->assertWithinLimits(LoanCategory::findOrFail($data['loan_category_id']), (float) $data['amount_applied'], 'amount_applied');

        $status = $this->borrowingStatus($customer, $data['loan_category_id'], $data['amount_applied']);
        if (! $status['allowed']) {
            throw ValidationException::withMessages(['customer_id' => $status['reasons']]);
        }

        $loan = DB::transaction(function () use ($customer, $data, $employee, $status): Loan {
            $loan = $this->loans->apply($customer, $data, $employee);
            if ($status['topup'] !== null) {
                $loan->update(['topup_of_loan_id' => $status['topup']['loan_id']]);
            }
            $this->record($loan, 'APPLIED', null, $employee, ['amount' => (float) $loan->amount_applied, 'topup_of' => $status['topup']['loan_number'] ?? null]);

            return $loan;
        });

        $this->recordCreditAssessment($loan, $employee);

        return $loan;
    }

    /**
     * §36: a new application arrives with its advisory credit recommendation already attached, so a Credit Officer never
     * opens a request without one. It writes a single loan_assessments row outside the application transaction and
     * changes nothing on the loan; the assessment is advisory, so a failure here is reported and never blocks the
     * application.
     */
    private function recordCreditAssessment(Loan $loan, Employee $employee): void
    {
        if (! config('credit.record_on_application')) {
            return;
        }

        rescue(fn (): LoanAssessment => app(CreditAssessment::class)->record($loan, $employee), report: true);
    }

    /**
     * Loan officer edits the application; a returned application goes back to the branch manager.
     * Handwritten note: "if approved can't edit".
     *
     * @param  array{loan_category_id: int, group_id?: int|null, amount_applied: float, sessions: int, formula: string, fee_deduct: bool, reason: string, instalment?: float}  $data
     */
    public function update(Loan $loan, array $data, Employee $employee): Loan
    {
        $this->assertStatus($loan, LoanStatus::PendingManagerApproval, LoanStatus::Returned);
        $this->eligibility->assertLoanCategoryAvailable($loan->customer, (int) $data['loan_category_id'], 'category_id');
        $category = LoanCategory::findOrFail($data['loan_category_id']);

        if ($data['amount_applied'] < (float) $category->amount_from || $data['amount_applied'] > (float) $category->amount_to) {
            throw ValidationException::withMessages(['how_loan' => "Loan amount must be between {$category->level_label}"]);
        }
        if ($data['sessions'] < $category->repayment_from || $data['sessions'] > $category->repayment_to) {
            throw ValidationException::withMessages(['session' => "Number of repayments must be between {$category->repayment_from} - {$category->repayment_to}"]);
        }

        return DB::transaction(function () use ($loan, $data, $category, $employee): Loan {
            $from = $loan->status;
            $loan->fill([
                'loan_category_id' => $category->id,
                'group_id' => $data['group_id'] ?? null,
                'amount_applied' => $data['amount_applied'],
                'duration' => $category->duration,
                'sessions' => $data['sessions'],
                'instalment' => $data['instalment'] ?? 0,
                'formula' => $data['formula'],
                'fee_deduct' => $data['fee_deduct'],
                'reason' => $data['reason'],
                'interest_rate' => $category->interest_rate,
                'status' => LoanStatus::PendingManagerApproval,
            ]);
            $loan->setRelation('category', $category);
            $this->loans->price($loan, (float) $data['amount_applied']);
            $loan->save();

            $this->record($loan, $from === LoanStatus::Returned ? 'RESUBMITTED' : 'MODIFIED', $from, $employee);

            return $loan;
        });
    }

    /**
     * Branch manager approval with the "Approved Loan" amount (live view_Dataloan → Aprove).
     */
    public function approveByManager(Loan $loan, float $approvedAmount, Employee $employee): Loan
    {
        $this->assertStatus($loan, LoanStatus::PendingManagerApproval);
        $loan->loadMissing(['category', 'customer']);

        $this->eligibility->assertLoanCategoryAvailable($loan->customer, (int) $loan->loan_category_id, 'loan_aprove');
        $this->eligibility->assertNotFrozen($loan->customer, 'loan_aprove');
        if (! $this->eligibility->for($loan->customer)['kyc_complete']) {
            throw ValidationException::withMessages(['loan_aprove' => "Please wait for the customer's KYC to be verified!"]);
        }
        if ($approvedAmount > (float) $loan->category->amount_to) {
            throw ValidationException::withMessages(['loan_aprove' => 'Approved loan must not exceed '.money($loan->category->amount_to)]);
        }
        $this->assertSegregated($loan, $employee, []);

        return DB::transaction(function () use ($loan, $approvedAmount, $employee): Loan {
            $loan->amount_approved = $approvedAmount;
            $this->loans->price($loan, $approvedAmount);
            $loan->approved_at = now();
            $loan->status = $loan->category->requires_mandate ? LoanStatus::MandatePendingOtp : LoanStatus::PendingCreditReview;
            $loan->save();

            $this->record($loan, 'MANAGER_APPROVED', LoanStatus::PendingManagerApproval, $employee, ['amount_approved' => $approvedAmount]);

            return $loan;
        });
    }

    public function reject(Loan $loan, string $reason, Employee $employee): Loan
    {
        $this->assertStatus($loan, LoanStatus::PendingManagerApproval, LoanStatus::PendingCreditReview, LoanStatus::MandateFailed, LoanStatus::MandatePendingOtp);
        $from = $loan->status;
        $loan->update(['status' => LoanStatus::Rejected, 'decision_reason' => $reason]);
        $this->record($loan, $from === LoanStatus::PendingCreditReview ? 'CREDIT_REJECTED' : 'MANAGER_REJECTED', $from, $employee, ['reason' => $reason]);

        return $loan;
    }

    /**
     * "Modify → back to loan officer" (manager, credit officer, or after a mandate failure).
     */
    public function returnForModification(Loan $loan, string $reason, Employee $employee): Loan
    {
        $this->assertStatus($loan, LoanStatus::PendingManagerApproval, LoanStatus::PendingCreditReview, LoanStatus::MandateFailed, LoanStatus::MandatePendingOtp);
        $from = $loan->status;
        $loan->update(['status' => LoanStatus::Returned, 'decision_reason' => $reason, 'telco_matched' => null]);
        $this->record($loan, 'RETURNED_FOR_MODIFICATION', $from, $employee, ['reason' => $reason]);

        return $loan;
    }

    /**
     * POST /bank/e-mandate: register the mandate, the bank sends an OTP to the account holder.
     *
     * @param  array{bank_name: string, account_number: string, account_name: string}  $details
     * @return array{success: bool, message: string}
     */
    public function createMandate(Loan $loan, array $details, Employee $employee): array
    {
        $this->assertStatus($loan, LoanStatus::MandatePendingOtp, LoanStatus::MandateFailed);

        $mandate = LoanMandate::create($details + [
            'company_id' => $loan->company_id,
            'loan_id' => $loan->id,
            'employee_id' => $employee->id,
            'status' => LoanMandate::PENDING_OTP,
        ]);

        $result = $this->bank->create($mandate);
        if (! $result->success) {
            $mandate->update(['status' => LoanMandate::FAILED, 'failure_reason' => $result->message]);
            $this->transition($loan, LoanStatus::MandateFailed, 'MANDATE_FAILED', $employee, ['reason' => $result->message]);

            return ['success' => false, 'message' => $result->message ?? 'E-mandate could not be created'];
        }

        $mandate->update(['mandate_reference' => $result->reference]);
        $this->transition($loan, LoanStatus::MandatePendingOtp, 'MANDATE_CREATED', $employee, ['mandate_reference' => $result->reference]);

        return ['success' => true, 'message' => 'E-mandate created, OTP sent to the customer'];
    }

    /**
     * POST /bank/e-mandate/verify-otp. Failure → MANDATE_FAILED (retry OTP or modify details).
     *
     * @return array{success: bool, message: string}
     */
    public function verifyMandateOtp(Loan $loan, string $otp, Employee $employee): array
    {
        $this->assertStatus($loan, LoanStatus::MandatePendingOtp, LoanStatus::MandateFailed);
        $mandate = $loan->mandate()->first();

        if ($mandate === null || $mandate->mandate_reference === null) {
            throw ValidationException::withMessages(['otp' => 'Create the e-mandate first']);
        }

        $result = $this->bank->verifyOtp($mandate, $otp);
        $mandate->increment('otp_attempts');

        if (! $result->success) {
            $mandate->update(['status' => LoanMandate::FAILED, 'failure_reason' => $result->message]);
            $this->transition($loan, LoanStatus::MandateFailed, 'MANDATE_OTP_FAILED', $employee, ['reason' => $result->message, 'attempt' => $mandate->otp_attempts]);

            return ['success' => false, 'message' => $result->message ?? 'Wrong OTP'];
        }

        $mandate->update(['status' => LoanMandate::ACTIVE, 'failure_reason' => null, 'activated_at' => now()]);
        $this->transition($loan, LoanStatus::PendingCreditReview, 'MANDATE_ACTIVE', $employee, ['mandate_reference' => $mandate->mandate_reference]);

        return ['success' => true, 'message' => 'E-mandate activated successfully'];
    }

    /**
     * Credit officer: POST /vodacom/kyc-verify — the name registered on the customer's number must match.
     *
     * @return array{matched: bool, registered_name: string|null, message: string}
     */
    public function verifyTelco(Loan $loan, Employee $employee): array
    {
        $this->assertStatus($loan, LoanStatus::PendingCreditReview);
        $customer = $loan->customer;

        $result = $this->vodacom->kycLookup((string) $customer->phone, $customer->full_name);
        $matched = $result->found && $this->namesMatch($customer, (string) $result->registeredName);

        $loan->update(['telco_name' => $result->registeredName, 'telco_matched' => $matched, 'telco_verified_at' => now()]);
        $this->record($loan, $matched ? 'TELCO_VERIFIED' : 'TELCO_NAME_MISMATCH', $loan->status, $employee, [
            'phone' => $customer->phone,
            'registered_name' => $result->registeredName,
            'message' => $result->message,
        ]);

        return [
            'matched' => $matched,
            'registered_name' => $result->registeredName,
            'message' => $matched ? 'Name and number verified successfully' : ($result->message ?? 'Name mismatch: '.$result->registeredName),
        ];
    }

    /**
     * Credit officer approval → PENDING_FINANCE; the reference number is generated here (handwritten note).
     */
    public function approveCredit(Loan $loan, Employee $employee): Loan
    {
        $this->assertStatus($loan, LoanStatus::PendingCreditReview);
        $this->eligibility->assertNotFrozen($loan->customer, 'loan');

        if ($loan->telco_matched !== true) {
            throw ValidationException::withMessages(['loan' => $loan->telco_verified_at === null
                ? 'Verify the customer name and number with Vodacom before approving'
                : 'Name mismatch: modify or reject the loan']);
        }
        $this->assertSegregated($loan, $employee, self::MANAGER_STAGE);
        if ($loan->agreement_file === null) {
            throw ValidationException::withMessages(['loan' => self::AGREEMENT_MISSING]);
        }

        return DB::transaction(function () use ($loan, $employee): Loan {
            $loan->update([
                'status' => LoanStatus::PendingFinance,
                'reference_number' => $loan->reference_number ?? $this->newReferenceNumber($loan),
            ]);
            $this->record($loan, 'CREDIT_APPROVED', LoanStatus::PendingCreditReview, $employee, ['reference_number' => $loan->reference_number]);

            return $loan;
        });
    }

    /**
     * The customer's filled and signed copy of the generated agreement (PDF). Only once the branch manager has
     * approved: before that the terms can still change. Uploading again replaces the file.
     */
    public function uploadAgreement(Loan $loan, UploadedFile $file, Employee $employee): Loan
    {
        if (! in_array($loan->status, LoanStatus::agreementAvailable(), true)) {
            throw ValidationException::withMessages(['attach' => self::AGREEMENT_NOT_READY]);
        }

        $replaced = $loan->agreement_file;
        $loan->update([
            'agreement_file' => $file->store('loans/agreements', 'public'),
            'agreement_uploaded_at' => now(),
            'agreement_uploaded_by' => $employee->id,
        ]);
        $this->record($loan, $replaced === null ? 'AGREEMENT_UPLOADED' : 'AGREEMENT_REPLACED', $loan->status, $employee);

        return $loan;
    }

    /**
     * Finance: POST /loans/{id}/prepare-disbursement — generates the batch id.
     */
    /**
     * @param  array{source_account?: string|null, source_bank_account_id?: int|null}|null  $source
     */
    public function prepareDisbursement(Loan $loan, Employee $employee, ?array $source = null): LoanDisbursement
    {
        return DB::transaction(function () use ($loan, $employee, $source): LoanDisbursement {
            $loan = Loan::lockForUpdate()->findOrFail($loan->id);
            $this->assertStatus($loan, LoanStatus::PendingFinance);
            $this->assertSegregated($loan, $employee, self::FINANCE_EARLIER_STAGES);

            $disbursement = $this->newBatch($loan, 'vodacom', $employee, $source);
            $this->assertSourceFunds($loan, $disbursement);
            $this->transition($loan, LoanStatus::AwaitingDisbursement, 'DISBURSEMENT_PREPARED', $employee, ['batch_id' => $disbursement->batch_id, 'amount' => (float) $disbursement->amount, 'source' => $disbursement->sourceLabel()]);

            return $disbursement;
        });
    }

    /**
     * Finance taps "Disburse": POST /vodacom/disbursement-request, then completes it in the Vodacom portal.
     * The result arrives on the callback (the test driver may simulate it immediately).
     *
     * @param  array{source_account?: string|null, source_bank_account_id?: int|null}|null  $source  changes the prepared batch's source
     * @return array{status: LoanStatus, message: string, portal_url: string|null, batch_id: string}
     */
    public function requestDisbursement(Loan $loan, Employee $employee, ?array $source = null): array
    {
        $disbursement = DB::transaction(function () use ($loan, $employee, $source): LoanDisbursement {
            $locked = Loan::lockForUpdate()->findOrFail($loan->id);
            $this->assertStatus($locked, LoanStatus::AwaitingDisbursement);
            $this->assertSegregated($locked, $employee, self::FINANCE_EARLIER_STAGES);
            $disbursement = $locked->latestDisbursement()->lockForUpdate()->first();

            if ($disbursement === null || $disbursement->channel !== 'vodacom' || $disbursement->status !== LoanDisbursement::PREPARED) {
                throw ValidationException::withMessages(['loan' => 'This disbursement has already been sent to Vodacom']);
            }

            if ($source !== null && filled($source['source_account'] ?? null)) {
                $disbursement->update($this->resolveSource($locked, $source));
            }
            $this->assertSourceFunds($locked, $disbursement);

            $disbursement->update(['status' => LoanDisbursement::REQUESTED, 'requested_by' => $employee->id, 'requested_at' => now()]);
            $this->record($locked, 'DISBURSEMENT_REQUESTED', $locked->status, $employee, ['batch_id' => $disbursement->batch_id, 'attempt' => $disbursement->attempt, 'source' => $disbursement->sourceLabel()]);

            return $disbursement;
        });

        $result = $this->vodacom->requestDisbursement($disbursement);
        $disbursement->update(['provider_reference' => $result->providerReference]);

        if (! $result->accepted || $result->immediateSuccess === false) {
            $this->failDisbursement($disbursement, $result->message ?? 'API error', $employee);
        } elseif ($result->immediateSuccess === true) {
            $this->completeDisbursement($disbursement, $result->providerReference, $employee);
        }

        $loan->refresh();

        return [
            'status' => $loan->status,
            'batch_id' => $disbursement->batch_id,
            'portal_url' => $this->vodacom->portalUrl(),
            'message' => match ($loan->status) {
                LoanStatus::Active => 'Loan Disbursed successfully',
                LoanStatus::DisbursementFailed => 'Disbursement failed: '.($result->message ?? 'API error'),
                LoanStatus::Escalated => 'Disbursement failed 3 times and has been ESCALATED',
                default => 'Disbursement request sent to Vodacom, complete it in the Vodacom portal',
            },
        ];
    }

    /**
     * POST /webhooks/vodacom/disbursement-status. Idempotent: a batch that already has a result is ignored.
     *
     * @return array{status: string, loan_status: string|null}
     */
    public function handleCallback(DisbursementCallback $callback): array
    {
        $disbursement = LoanDisbursement::where('batch_id', $callback->batchId)->first();
        if ($disbursement === null) {
            return ['status' => 'UNKNOWN_BATCH', 'loan_status' => null];
        }
        if (! in_array($disbursement->status, [LoanDisbursement::PREPARED, LoanDisbursement::REQUESTED], true)) {
            return ['status' => 'ALREADY_PROCESSED', 'loan_status' => $disbursement->loan->status->value];
        }

        $disbursement->update(['callback_payload' => $callback->payload]);

        if ($callback->success) {
            $this->completeDisbursement($disbursement, $callback->transactionId, null);
        } else {
            $this->failDisbursement($disbursement, $callback->reason ?? 'Disbursement failed', null);
        }

        return ['status' => 'PROCESSED', 'loan_status' => $disbursement->loan->fresh()->status->value];
    }

    /**
     * Documents: "IF status == DISBURSEMENT_FAILED → allow retry ELSE reject"; max 3 attempts; each retry has a new
     * batch id and an audit record {action: RETRY_DISBURSEMENT, user, timestamp, batch_id, attempt}. Finance edits nothing.
     *
     * @param  array{source_account?: string|null, source_bank_account_id?: int|null}|null  $source  always the HQ PRINCIPAL A/C
     * @return array{status: LoanStatus, message: string, portal_url: string|null, batch_id: string}
     */
    public function retryDisbursement(Loan $loan, Employee $employee, ?array $source = null): array
    {
        DB::transaction(function () use ($loan, $employee, $source): void {
            $locked = Loan::lockForUpdate()->findOrFail($loan->id);
            $this->assertStatus($locked, LoanStatus::DisbursementFailed);
            $this->assertSegregated($locked, $employee, self::FINANCE_EARLIER_STAGES);

            if ((int) $locked->disbursement_attempts >= $this->maxAttempts()) {
                throw ValidationException::withMessages(['loan' => 'Maximum disbursement retries reached']);
            }

            $disbursement = $this->newBatch($locked, 'vodacom', $employee, $source);
            $this->assertSourceFunds($locked, $disbursement);
            $this->transition($locked, LoanStatus::AwaitingDisbursement, 'RETRY_DISBURSEMENT', $employee, [
                'user' => $employee->full_name,
                'timestamp' => now()->toIso8601String(),
                'batch_id' => $disbursement->batch_id,
                'attempt' => $disbursement->attempt,
            ]);
        });

        return $this->requestDisbursement($loan->fresh(), $employee);
    }

    /**
     * Manual decision on an ESCALATED disbursement: cancel the loan, move it to suspense, or pay through a
     * different channel (Airtel, bank or branch cash with a withdrawal code).
     *
     * @param  array{source_account?: string|null, source_bank_account_id?: int|null}|null  $source  source account for the new channel
     */
    public function resolveEscalation(Loan $loan, string $action, ?string $channel, string $reason, Employee $employee, ?array $source = null): Loan
    {
        return DB::transaction(function () use ($loan, $action, $channel, $reason, $employee, $source): Loan {
            $loan = Loan::lockForUpdate()->findOrFail($loan->id);
            $this->assertStatus($loan, LoanStatus::Escalated);
            if ($action === 'other_channel') {
                $this->assertSegregated($loan, $employee, self::FINANCE_EARLIER_STAGES);
            }

            match ($action) {
                'cancel' => $this->cancel($loan, $reason, $employee),
                'suspense' => $this->transition($loan, LoanStatus::DisbursementSuspense, 'MOVED_TO_SUSPENSE', $employee, ['reason' => $reason]),
                'other_channel' => $this->switchChannel($loan, (string) $channel, $reason, $employee, $source),
                default => throw ValidationException::withMessages(['action' => 'Unknown action']),
            };

            return $loan->fresh();
        });
    }

    /**
     * Inferred: a loan parked in suspense can be sent back to Finance, which starts a new retry cycle.
     */
    public function requeue(Loan $loan, Employee $employee): Loan
    {
        $this->assertStatus($loan, LoanStatus::DisbursementSuspense);
        $loan->update(['disbursement_attempts' => 0]);
        $this->transition($loan, LoanStatus::PendingFinance, 'REQUEUED_FROM_SUSPENSE', $employee);

        return $loan;
    }

    /**
     * Finance confirms a disbursement paid through Airtel / bank (other channel) with the transfer reference.
     */
    public function confirmManualDisbursement(Loan $loan, string $reference, Employee $employee): Loan
    {
        $this->assertStatus($loan, LoanStatus::AwaitingDisbursement);
        $this->assertSegregated($loan, $employee, self::FINANCE_EARLIER_STAGES);
        $disbursement = $loan->latestDisbursement()->first();

        if ($disbursement === null || ! in_array($disbursement->channel, ['airtel', 'bank'], true) || $disbursement->status !== LoanDisbursement::REQUESTED) {
            throw ValidationException::withMessages(['reference' => 'This loan is not waiting for a manual disbursement']);
        }
        $this->assertSourceFunds($loan, $disbursement);

        $this->completeDisbursement($disbursement, $reference, $employee);

        return $loan->fresh();
    }

    /**
     * Branch cash-out with the SMS withdrawal code (live Loan Withdrawal behaviour, used as the "cash" channel).
     */
    public function cashOut(Loan $loan, string $code, Employee $employee): Loan
    {
        $this->assertStatus($loan, LoanStatus::AwaitingDisbursement);
        $disbursement = $loan->latestDisbursement()->first();

        if ($disbursement === null || $disbursement->channel !== 'cash' || $disbursement->status !== LoanDisbursement::REQUESTED) {
            throw ValidationException::withMessages(['code' => 'This loan is not waiting for a cash withdrawal']);
        }
        if ($loan->withdrawal_code === null || ! hash_equals($loan->withdrawal_code, $code)) {
            throw ValidationException::withMessages(['code' => 'Invalid withdrawal code']);
        }
        $this->assertSourceFunds($loan, $disbursement);

        $this->completeDisbursement($disbursement, 'CASH-'.$disbursement->batch_id, $employee);

        return $loan->fresh();
    }

    /**
     * POST /loans/{id}/close — only when nothing is outstanding.
     */
    public function close(Loan $loan, Employee $employee): Loan
    {
        $this->assertStatus($loan, ...LoanStatus::repayable());
        $outstanding = $this->loans->outstanding($loan)['total'];

        if ($outstanding > 0.5) {
            throw ValidationException::withMessages(['loan' => 'Loan still has an outstanding balance of '.money($outstanding)]);
        }

        $from = $loan->status;
        $this->loans->close($loan);
        $this->record($loan, 'CLOSED', $from, $employee, ['early_settlement' => $loan->early_settlement, 'freeze_started_at' => $loan->freeze_started_at?->toIso8601String(), 'freeze_days' => $loan->freeze_days, 'frozen_until' => $loan->frozen_until?->toIso8601String()]);

        return $loan;
    }

    public function comment(Loan $loan, string $comment, Employee $employee): void
    {
        $this->record($loan, 'COMMENT', $loan->status, $employee, ['comment' => $comment]);
    }

    /**
     * Net cash sent to the customer: approved principal − deducted loan fee − balance of the loan being topped up.
     * Inferred: salary advance and penalty deductions shown on the approval screen are informational only.
     */
    public function netDisbursement(Loan $loan): float
    {
        $fee = $loan->fee_deduct ? (float) $loan->loan_fee : 0.0;
        $topup = $loan->topupOf && in_array($loan->topupOf->status, LoanStatus::repayable(), true) ? $this->loans->outstanding($loan->topupOf)['total'] : 0.0;

        return round(max(0, (float) $loan->amount_approved - $fee - $topup), 2);
    }

    /**
     * Amount the source account decreases by when the loan is posted: the full principal from the HQ PRINCIPAL
     * A/C (a deducted fee is kept in the branch LOAN FEE A/C), or principal less the deducted fee from a bank account.
     */
    public function sourceOutflow(Loan $loan, string $sourceAccount): float
    {
        $fee = $loan->fee_deduct ? (float) $loan->loan_fee : 0.0;

        return round($sourceAccount === LoanDisbursement::SOURCE_BANK ? (float) $loan->amount_approved - $fee : (float) $loan->amount_approved, 2);
    }

    /**
     * The account every loan is disbursed from (HQ PRINCIPAL A/C), with its live balance and what this loan needs.
     *
     * @return array{cash: array{value: string, label: string, balance: float, required: float}}
     */
    public function sourceOptions(Loan $loan): array
    {
        $loan->loadMissing('branch');
        $ledger = app(Ledger::class);

        return [
            'cash' => [
                'value' => LoanDisbursement::SOURCE_CASH,
                'label' => Account::Principal->label().' (HQ CASH)',
                'balance' => $ledger->balance($loan->company_id, Account::Principal) + 0.0,
                'required' => $this->sourceOutflow($loan, LoanDisbursement::SOURCE_CASH),
            ],
        ];
    }

    public function maxAttempts(): int
    {
        return (int) config('integrations.vodacom.max_disbursement_attempts', 3);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(Loan $loan, string $action, ?LoanStatus $from, ?Employee $employee, array $context = []): void
    {
        AuditLog::create([
            'company_id' => $loan->company_id,
            'employee_id' => $employee?->id,
            'action' => $action,
            'auditable_type' => $loan->getMorphClass(),
            'auditable_id' => $loan->id,
            'before' => $from ? ['status' => $from->value] : null,
            'after' => ['status' => $loan->status->value],
            'context' => $context === [] ? null : $context,
            'ip_address' => request()?->ip(),
        ]);
    }

    private function completeDisbursement(LoanDisbursement $disbursement, ?string $providerReference, ?Employee $employee): void
    {
        DB::transaction(function () use ($disbursement, $providerReference, $employee): void {
            $disbursement = LoanDisbursement::lockForUpdate()->findOrFail($disbursement->id);
            if (! in_array($disbursement->status, [LoanDisbursement::PREPARED, LoanDisbursement::REQUESTED], true)) {
                return;
            }

            $loan = Loan::lockForUpdate()->with(['topupOf', 'customer', 'company'])->findOrFail($disbursement->loan_id);
            if (LoanDisbursement::where('loan_id', $loan->id)->where('status', LoanDisbursement::SUCCESS)->exists()) {
                return;
            }

            $today = CarbonImmutable::today();
            $previous = $loan->topupOf;

            $entry = $this->loans->withdraw($loan, $today, $employee, 'LOAN DISBURSEMENT', $disbursement->channel, $disbursement->ledgerSource());
            $disbursement->update([
                'status' => LoanDisbursement::SUCCESS,
                'provider_reference' => $providerReference ?? $disbursement->provider_reference,
                'completed_at' => now(),
                'journal_entry_id' => $entry->id,
            ]);

            if ($previous !== null && in_array($previous->status, LoanStatus::repayable(), true)) {
                $balance = $this->loans->outstanding($previous)['total'];
                if ($balance > 0) {
                    $settlement = $this->loans->deposit($previous, $balance, $today, 'TOPUP', $employee);
                    $this->recordOffset($loan, $previous, $settlement, (float) $disbursement->amount);
                    $this->record($previous->fresh(), 'SETTLED_BY_TOPUP', $previous->status, $employee, ['amount' => $balance, 'new_loan' => $loan->loan_number]);
                }
            }

            $loan->refresh();
            $this->record($loan, 'DISBURSED', LoanStatus::AwaitingDisbursement, $employee, [
                'batch_id' => $disbursement->batch_id,
                'attempt' => $disbursement->attempt,
                'channel' => $disbursement->channel,
                'amount' => (float) $disbursement->amount,
                'provider_reference' => $disbursement->provider_reference,
                'source' => $disbursement->sourceLabel(),
                'journal_reference' => $entry->reference,
            ]);

            $this->notify($loan, 'Mkopo wako wa TSH '.money($loan->amount_approved).' umetolewa. Umepokea TSH '.money($disbursement->amount)
                .'. Kumbukumbu ya malipo: '.($loan->reference_number ?? $loan->loan_number).'. Rejesho la kwanza: '.$loan->schedules()->value('due_date').'.');
        });
    }

    private function failDisbursement(LoanDisbursement $disbursement, string $reason, ?Employee $employee): void
    {
        DB::transaction(function () use ($disbursement, $reason, $employee): void {
            $disbursement = LoanDisbursement::lockForUpdate()->findOrFail($disbursement->id);
            if (! in_array($disbursement->status, [LoanDisbursement::PREPARED, LoanDisbursement::REQUESTED], true)) {
                return;
            }

            $loan = Loan::lockForUpdate()->findOrFail($disbursement->loan_id);
            $disbursement->update(['status' => LoanDisbursement::FAILED, 'failure_reason' => $reason, 'completed_at' => now()]);

            $escalate = $disbursement->attempt >= $this->maxAttempts();
            $loan->disbursement_attempts = $disbursement->attempt;
            $this->transition($loan, LoanStatus::DisbursementFailed, 'DISBURSEMENT_FAILED', $employee, ['batch_id' => $disbursement->batch_id, 'attempt' => $disbursement->attempt, 'reason' => $reason]);

            if ($escalate) {
                $this->transition($loan, LoanStatus::Escalated, 'ESCALATED', $employee, ['attempts' => $disbursement->attempt]);
            }
        });
    }

    private function cancel(Loan $loan, string $reason, Employee $employee): void
    {
        $loan->decision_reason = $reason;
        $loan->latestDisbursement()->whereIn('status', [LoanDisbursement::PREPARED, LoanDisbursement::REQUESTED])->update(['status' => LoanDisbursement::CANCELLED]);
        $this->transition($loan, LoanStatus::Cancelled, 'CANCELLED', $employee, ['reason' => $reason]);
    }

    /**
     * @param  array{source_account?: string|null, source_bank_account_id?: int|null}|null  $source
     */
    private function switchChannel(Loan $loan, string $channel, string $reason, Employee $employee, ?array $source = null): void
    {
        if (! in_array($channel, ['airtel', 'bank', 'cash'], true)) {
            throw ValidationException::withMessages(['channel' => 'Select a valid channel']);
        }

        $disbursement = $this->newBatch($loan, $channel, $employee, $source);
        $this->assertSourceFunds($loan, $disbursement);
        $disbursement->update(['status' => LoanDisbursement::REQUESTED, 'requested_by' => $employee->id, 'requested_at' => now()]);

        if ($channel === 'cash') {
            $loan->withdrawal_code = (string) random_int(1000, 9999);
        }
        $loan->disbursement_channel = $channel;
        $this->transition($loan, LoanStatus::AwaitingDisbursement, 'OTHER_CHANNEL', $employee, ['channel' => $channel, 'batch_id' => $disbursement->batch_id, 'reason' => $reason]);

        if ($channel === 'cash') {
            $this->loans->sendWithdrawalCode($loan);
            $this->sms->send((string) $loan->customer->phone, (string) SmsLog::where('customer_id', $loan->customer_id)->latest('id')->value('message'));
        }
    }

    /**
     * @param  array{source_account?: string|null, source_bank_account_id?: int|null}|null  $source  always resolves to the HQ PRINCIPAL A/C
     */
    private function newBatch(Loan $loan, string $channel, ?Employee $employee, ?array $source = null): LoanDisbursement
    {
        $attempt = (int) $loan->disbursement_attempts + 1;
        $source = $this->resolveSource($loan, $source ?? []);
        $prefix = match ($channel) {
            'airtel' => 'AIRT',
            'bank' => 'BANK',
            'cash' => 'CASH',
            default => 'VODA',
        };

        return LoanDisbursement::create([
            'company_id' => $loan->company_id,
            'branch_id' => $loan->branch_id,
            'loan_id' => $loan->id,
            'batch_id' => $prefix.now()->format('ymd').strtoupper(Str::random(8)),
            'attempt' => $channel === 'vodacom' ? $attempt : max(1, (int) $loan->disbursement_attempts),
            'channel' => $channel,
            'phone' => $loan->customer->phone,
            'amount' => $this->netDisbursement($loan),
            'status' => LoanDisbursement::PREPARED,
            'prepared_by' => $employee?->id,
        ] + $source);
    }

    /**
     * @param  array{source_account?: string|null, source_bank_account_id?: int|null}  $source
     * @return array{source_account: string, source_bank_account_id: int|null}
     */
    private function resolveSource(Loan $loan, array $source): array
    {
        $account = (string) ($source['source_account'] ?? '');
        if ($account !== '' && $account !== LoanDisbursement::SOURCE_CASH) {
            throw ValidationException::withMessages(['source_account' => 'Loans are disbursed from the PRINCIPAL A/C only']);
        }

        return ['source_account' => LoanDisbursement::SOURCE_CASH, 'source_bank_account_id' => null];
    }

    /**
     * The chosen source must hold what the posting takes from it before any money is sent.
     */
    private function assertSourceFunds(Loan $loan, LoanDisbursement $disbursement): void
    {
        $source = $disbursement->ledgerSource();
        $required = $this->sourceOutflow($loan, (string) ($disbursement->source_account ?? LoanDisbursement::SOURCE_CASH));
        $available = app(Ledger::class)->balance($loan->company_id, $source['account'], $source['branch'] ?? null, $source['bank'] ?? null);

        if ($available + 0.001 < $required) {
            throw ValidationException::withMessages(['source_account' => 'Insufficient balance in '.$disbursement->sourceLabel().': available '.money($available).', required '.money($required)]);
        }
    }

    /**
     * Record what the top-up settled internally (specification §13/§14): the old debt the customer never paid in cash,
     * split into the components it was made of, beside the cash they actually received. The money has already moved
     * through the ordinary repayment posting; this is the tracking attribute that keeps the offset out of the commission
     * base and puts it back before the dividend (§15).
     */
    private function recordOffset(Loan $loan, Loan $previous, LoanTransaction $settlement, float $cashDisbursed): void
    {
        LoanOffset::updateOrCreate(['new_loan_id' => $loan->id], [
            'company_id' => $loan->company_id,
            'branch_id' => $loan->branch_id,
            'customer_id' => $loan->customer_id,
            'old_loan_id' => $previous->id,
            'loan_transaction_id' => $settlement->id,
            'amount' => (float) $settlement->amount,
            'principal_amount' => (float) $settlement->principal,
            'penalty_amount' => (float) $settlement->penalty,
            'interest_amount' => (float) $settlement->interest,
            // The customer's salary advance is settled on its own product, never out of the top-up; it stays 0 here until
            // that flow exists, so the column never carries a figure the ledger cannot back.
            'salary_advance_amount' => 0,
            'insurance_amount' => (float) $settlement->insurance,
            'cash_disbursed' => round($cashDisbursed, 2),
            'settled_on' => $settlement->transaction_date,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function transition(Loan $loan, LoanStatus $to, string $action, ?Employee $employee, array $context = []): void
    {
        $from = $loan->status;
        $loan->status = $to;
        $loan->save();
        $this->record($loan, $action, $from, $employee, $context);
    }

    /**
     * Audit actions of the manager approval stage.
     *
     * @var list<string>
     */
    public const MANAGER_STAGE = ['MANAGER_APPROVED'];

    /**
     * Audit actions of the stages before Finance (manager approval, credit approval).
     *
     * @var list<string>
     */
    public const FINANCE_EARLIER_STAGES = ['MANAGER_APPROVED', 'CREDIT_APPROVED'];

    /**
     * Why the employee may not approve the loan's current stage, or null when they may (rule 6). The employee who applied
     * for (or last edited) the application never approves any stage; an approver of an earlier stage (manager approval,
     * credit approval) does not approve a later one. Only approvals since the latest (re)submission count. Explicit
     * `approvals.self_approve` lifts both blocks.
     *
     * @param  list<string>  $earlierStageActions  audit actions of the earlier approval stages
     */
    public function segregationBlockedReason(Loan $loan, Employee $employee, array $earlierStageActions): ?string
    {
        $logs = AuditLog::query()
            ->where('auditable_type', $loan->getMorphClass())
            ->where('auditable_id', $loan->id)
            ->whereIn('action', ['APPLIED', 'MODIFIED', 'RESUBMITTED', ...self::FINANCE_EARLIER_STAGES])
            ->orderBy('id')
            ->get(['id', 'action', 'employee_id']);
        $submittedAt = (int) $logs->whereIn('action', ['APPLIED', 'RESUBMITTED'])->max('id');

        $initiators = [$loan->employee_id, ...$logs->whereIn('action', ['APPLIED', 'MODIFIED', 'RESUBMITTED'])->pluck('employee_id')->all()];
        $earlierApprovers = $logs->where('id', '>', $submittedAt)->whereIn('action', $earlierStageActions)->pluck('employee_id')->all();

        $duties = app(SegregationOfDuties::class);

        return $duties->blockedReason($initiators, $employee, workflow: ApprovalPolicy::LOAN_APPROVALS)
            ?? $duties->blockedReason($earlierApprovers, $employee, SegregationOfDuties::STAGE_MESSAGE, workflow: ApprovalPolicy::LOAN_APPROVALS);
    }

    /**
     * @param  list<string>  $earlierStageActions
     */
    private function assertSegregated(Loan $loan, Employee $employee, array $earlierStageActions): void
    {
        $reason = $this->segregationBlockedReason($loan, $employee, $earlierStageActions);

        abort_if($reason !== null, 403, (string) $reason);
    }

    private function assertStatus(Loan $loan, LoanStatus ...$allowed): void
    {
        if (! in_array($loan->status, $allowed, true)) {
            throw ValidationException::withMessages(['loan' => "This action is not allowed while the loan is {$loan->status->label()}"]);
        }
    }

    /**
     * Inferred: the registered name matches when it contains the customer's first and last names (case and
     * punctuation insensitive; middle names are often missing or abbreviated on telco records).
     */
    private function namesMatch(Customer $customer, string $registeredName): bool
    {
        $normalise = fn (?string $value): string => trim((string) preg_replace('/[^A-Z]/', '', strtoupper((string) $value)));
        $words = array_map($normalise, preg_split('/\s+/', $registeredName) ?: []);

        return $normalise($customer->first_name) !== '' && in_array($normalise($customer->first_name), $words, true)
            && in_array($normalise($customer->last_name), $words, true);
    }

    private function notify(Loan $loan, string $message): void
    {
        SmsLog::create([
            'company_id' => $loan->company_id,
            'customer_id' => $loan->customer_id,
            'phone' => $loan->customer->phone,
            'message' => $message,
        ]);
        $this->sms->send((string) $loan->customer->phone, $message);
    }

    private function newReferenceNumber(Loan $loan): string
    {
        return 'MF'.now()->format('ym').str_pad((string) $loan->id, 6, '0', STR_PAD_LEFT);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Loans;

use App\Enums\Account;
use App\Enums\Duration;
use App\Enums\LoanStatus;
use App\Http\Requests\Api\Loans\LoanApplicationRequest;
use App\Http\Resources\Api\V1\Loans\LoanResource;
use App\Models\ApprovalPolicy;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Group;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanCategory;
use App\Models\LoanDisbursement;
use App\Models\LoanRecovery;
use App\Models\LoanTransaction;
use App\Models\WriteOffRequest;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Credit\CreditAssessment;
use App\Services\CustomerEligibility;
use App\Services\LoanCalculator;
use App\Services\LoanGuarantors;
use App\Services\LoanRecoveryService;
use App\Services\LoanService;
use App\Services\LoanWorkflow;
use App\Services\ReversalRequests;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Loan → Loan Application / Loan Pending / Loan Disbursed / Loan Withdrawal / Loan Rejected lists and the
 * loan detail page (live view_Dataloan) with repayment schedule and timeline.
 */
class LoanController extends LoanApiController
{
    /**
     * Stages of the lifecycle shown by each Loan menu page.
     *
     * @return array<string, list<LoanStatus>>
     */
    private function stages(): array
    {
        return [
            'pending' => [LoanStatus::PendingManagerApproval, LoanStatus::Returned, LoanStatus::MandatePendingOtp, LoanStatus::MandateFailed],
            'credit-review' => [LoanStatus::PendingCreditReview],
            // Approved by the branch manager, before credit approval: the agreement is printed, signed and uploaded here.
            'agreement' => [LoanStatus::MandatePendingOtp, LoanStatus::MandateFailed, LoanStatus::PendingCreditReview],
            'disbursement' => [LoanStatus::PendingFinance, LoanStatus::AwaitingDisbursement, LoanStatus::DisbursementFailed, LoanStatus::Escalated, LoanStatus::DisbursementSuspense],
            'disbursed' => LoanStatus::repayable(),
            'closed' => [LoanStatus::Closed, LoanStatus::WrittenOff],
            'rejected' => [LoanStatus::Rejected, LoanStatus::Cancelled],
        ];
    }

    public function __construct(
        private readonly LoanService $loans,
        private readonly LoanWorkflow $workflow,
        private readonly LoanRecoveryService $recoveries,
        private readonly CreditAssessment $assessments,
        private readonly LoanGuarantors $guarantors,
    ) {}

    /**
     * GET /loans?stage=pending|agreement|credit-review|disbursement|disbursed|closed|rejected&special=1&status=&branch_id=&from=&to=
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('loans.view');
        $request->validate([
            'stage' => ['nullable', Rule::in(array_keys($this->stages()))],
            'status' => ['nullable', Rule::enum(LoanStatus::class)],
        ]);

        $query = $this->scoped(Loan::query())
            ->with(['customer', 'branch', 'category', 'latestDisbursement.sourceBankAccount', 'latestDisbursement.branch', 'latestDisbursement.journalEntry'])
            ->when($request->filled('stage'), fn (Builder $query) => $query->status(...$this->stages()[$request->string('stage')->toString()]))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->has('special'), fn (Builder $query) => $query->where('is_special', $request->boolean('special')))
            ->when($request->filled('customer_id'), fn (Builder $query) => $query->where('customer_id', $request->integer('customer_id')))
            ->latest('id');
        $this->applyFilters($query, $request, 'created_at');

        $rows = $query->get();

        return response()->json([
            'data' => LoanResource::collection($rows),
            'special_count' => $request->input('stage') === 'pending'
                ? $this->scoped(Loan::query())->status(...$this->stages()['pending'])->where('is_special', true)->count()
                : null,
        ]);
    }

    /**
     * Loan detail (live view_Dataloan): customer header, guarantors, collateral, deductions, application, schedule,
     * repayments, mandate, disbursement attempts, top-up link and timeline.
     */
    public function show(Loan $loan, CustomerEligibility $eligibility): JsonResponse
    {
        $this->authorizeAny('loans.view');
        $this->ensureVisible($loan);

        $loan->load(['customer.region', 'customer.branch', 'customer.customerCategory', 'branch', 'category.customerType', 'employee', 'group', 'guarantors.region', 'collaterals', 'schedules', 'mandate', 'disbursements.requester', 'disbursements.sourceBankAccount', 'disbursements.branch', 'disbursements.journalEntry.lines.account', 'latestDisbursement.sourceBankAccount', 'latestDisbursement.branch', 'latestDisbursement.journalEntry', 'topupOf', 'writeOff', 'legacyImportRow.import:id,file_name,year,approved_at']);
        $customer = $loan->customer;
        $recovery = $loan->writeOff ? $this->recoveries->position($loan) : null;
        $viewer = $this->currentEmployee();

        return response()->json(['data' => [
            'loan' => new LoanResource($loan),
            'customer' => [
                'id' => $customer->id,
                'full_name' => $customer->full_name,
                'short_name' => $customer->short_name,
                'customer_code' => $customer->customer_code,
                'photo_url' => $customer->photo_url,
                'phone' => $customer->phone,
                'gender' => $customer->gender,
                'age' => $customer->age ?? $customer->date_of_birth?->age,
                'monthly_income' => (float) $customer->monthly_income,
                'business_type' => $customer->business_type,
                'place_of_business' => $customer->place_of_business,
                'region' => $customer->region?->name,
                'district' => $customer->district,
                'ward' => $customer->ward,
                'street' => $customer->street,
                'id_number' => $customer->id_number,
                'branch' => $customer->branch?->name,
                'customer_type' => $customer->customerCategory?->name,
                'status_label' => $customer->status_label,
                'kyc_status' => $customer->kyc_status,
                'created_at' => $customer->created_at?->toDateTimeString(),
            ],
            'guarantors' => $loan->guarantors->map(fn ($guarantor): array => [
                'id' => $guarantor->id,
                'full_name' => trim("{$guarantor->first_name} {$guarantor->middle_name} {$guarantor->last_name}"),
                'phone' => $guarantor->phone,
                'gender' => $guarantor->gender,
                'marital_status' => $guarantor->marital_status,
                'id_number' => $guarantor->id_number,
                'relationship' => $guarantor->relationship,
                'address' => collect([$guarantor->region?->name, $guarantor->district, $guarantor->ward, $guarantor->street])->filter()->implode(','),
            ])->values(),
            // Every guarantor the customer has registered (profile or earlier loans), one per phone, minus those already on this loan.
            'available_guarantors' => $customer->guarantors()
                ->where(fn ($query) => $query->whereNull('loan_id')->orWhere('loan_id', '!=', $loan->id))
                ->whereNotIn('phone', $loan->guarantors->pluck('phone'))
                ->orderByDesc('id')
                ->get()
                ->unique('phone')
                ->map(fn ($guarantor): array => [
                    'value' => (string) $guarantor->id,
                    'label' => trim("{$guarantor->first_name} {$guarantor->last_name}")." / {$guarantor->phone}",
                ])->values(),
            'collaterals' => $loan->collaterals->map(fn ($collateral): array => [
                'id' => $collateral->id,
                'name' => $collateral->name,
                'type' => $collateral->type,
                'location' => $collateral->location,
                'value' => (float) $collateral->value,
            ])->values(),
            'collateral_attachment' => $loan->collateral_attachment ? asset('storage/'.$loan->collateral_attachment) : null,
            'deductions' => $this->loans->deductions($loan),
            'net_disbursement' => $this->workflow->netDisbursement($loan),
            'outstanding' => in_array($loan->status, LoanStatus::disbursed(), true) ? $this->loans->outstanding($loan) : null,
            'paid_amount' => $loan->paid_amount,
            'schedules' => $loan->schedules->map(fn ($schedule): array => [
                'id' => $schedule->id,
                'due_date' => CarbonImmutable::parse($schedule->due_date)->toDateString(),
                'amount' => (float) $schedule->amount,
                'paid_amount' => (float) $schedule->paid_amount,
                'pending' => round((float) $schedule->amount - (float) $schedule->paid_amount, 2),
            ])->values(),
            'transactions' => $loan->transactions()->with(['reverser', 'journalEntry', 'reversalJournalEntry', 'paymentAllocation.payment'])->latest('transaction_date')->latest('id')->get()->map(function (LoanTransaction $transaction) use ($loan, $viewer): array {
                $transaction->setRelation('loan', $loan);
                $blocker = $transaction->type === 'deposit' ? $this->loans->repaymentReverseBlockedReason($transaction, $viewer) : null;

                return [
                    'id' => $transaction->id,
                    'date' => CarbonImmutable::parse($transaction->transaction_date)->toDateString(),
                    'type' => $transaction->type,
                    'description' => $transaction->description,
                    'method' => $transaction->method,
                    'amount' => (float) $transaction->amount,
                    'principal' => (float) $transaction->principal,
                    'penalty' => (float) $transaction->penalty,
                    'interest' => (float) $transaction->interest,
                    'reserve' => (float) $transaction->reserve,
                    'insurance' => (float) $transaction->insurance,
                    'receipt_number' => $transaction->paymentAllocation?->payment?->receipt_number,
                    'journal_reference' => $transaction->journalEntry?->reference,
                    'reversed' => $transaction->reversed_at !== null,
                    'reversed_at' => $transaction->reversed_at?->toDateTimeString(),
                    'reversed_by' => $transaction->reverser?->full_name,
                    'reversal_reason' => $transaction->reversal_reason,
                    'reversal_reference' => $transaction->reversalJournalEntry?->reference,
                    'can_reverse' => $transaction->type === 'deposit' && $blocker === null,
                    'reverse_blocked_reason' => $transaction->type === 'deposit' ? $blocker : null,
                    'reversal_request' => $transaction->type === 'deposit' ? $this->pendingReversal($transaction, $viewer) : null,
                ];
            })->values(),
            'can_reverse_disbursement' => ($disbursementBlocker = in_array($loan->status, LoanStatus::disbursed(), true) ? $this->loans->disbursementReverseBlockedReason($loan, $viewer) : 'The loan has not been disbursed.') === null,
            'reverse_disbursement_blocked_reason' => $disbursementBlocker,
            'disbursement_reversal_request' => $this->pendingReversal($loan, $viewer),
            'write_off' => $loan->writeOff ? [
                'amount' => (float) $loan->writeOff->amount,
                'principal_amount' => $loan->writeOff->principal_amount !== null ? (float) $loan->writeOff->principal_amount : null,
                'penalty_amount' => $loan->writeOff->penalty_amount !== null ? (float) $loan->writeOff->penalty_amount : null,
                'interest_amount' => $loan->writeOff->interest_amount !== null ? (float) $loan->writeOff->interest_amount : null,
                'insurance_amount' => $loan->writeOff->insurance_amount !== null ? (float) $loan->writeOff->insurance_amount : null,
                'components_status' => $recovery['components_status'] ?? null,
                'written_off_on' => $loan->writeOff->written_off_on?->toDateString(),
            ] : null,
            'write_off_request' => $this->writeOffRequest($loan, $viewer),
            'loan_fee' => [
                'amount' => (float) $loan->loan_fee,
                'deducted' => (bool) $loan->fee_deduct,
                'note' => $loan->fee_deduct ? 'Deducted at disbursement (fee income)' : 'Not deducted — not part of repayment',
            ],
            'recovery' => $recovery,
            'recovered_total' => $recovery['recovered'] ?? null,
            'recovery_status' => $recovery['status'] ?? null,
            'recoveries' => $loan->recoveries()->with(['employee', 'reverser', 'journalEntry', 'reversalJournalEntry', 'payment'])->latest('id')->get()
                ->map(fn (LoanRecovery $recovery): array => $this->recoveries->present($recovery, $viewer))->values(),
            'mandate' => $loan->mandate ? [
                'bank_name' => $loan->mandate->bank_name,
                'account_number' => $loan->mandate->account_number,
                'account_name' => $loan->mandate->account_name,
                'mandate_reference' => $loan->mandate->mandate_reference,
                'status' => $loan->mandate->status,
                'otp_attempts' => $loan->mandate->otp_attempts,
                'failure_reason' => $loan->mandate->failure_reason,
                'activated_at' => $loan->mandate->activated_at?->toDateTimeString(),
            ] : null,
            'disbursements' => $loan->disbursements->map(fn ($disbursement): array => [
                'id' => $disbursement->id,
                'batch_id' => $disbursement->batch_id,
                'attempt' => $disbursement->attempt,
                'channel' => $disbursement->channel,
                'phone' => $disbursement->phone,
                'amount' => (float) $disbursement->amount,
                'status' => $disbursement->status,
                'provider_reference' => $disbursement->provider_reference,
                'failure_reason' => $disbursement->failure_reason,
                'requested_by' => $disbursement->requester?->full_name,
                'requested_at' => $disbursement->requested_at?->toDateTimeString(),
                'completed_at' => $disbursement->completed_at?->toDateTimeString(),
                'source_account' => $disbursement->source_account ?? LoanDisbursement::SOURCE_CASH,
                'source_label' => $disbursement->sourceLabel(),
                'destination' => Account::LoanReceivable->label().' - '.$loan->loan_number,
                'journal_entry' => $disbursement->journalEntry ? $this->presentEntry($disbursement->journalEntry) : null,
            ])->values(),
            'disbursement_chain' => $this->disbursementChain($loan),
            'ledger' => $this->receivableLedger($loan),
            'max_disbursement_attempts' => $this->workflow->maxAttempts(),
            'topup_of' => $loan->topupOf ? ['id' => $loan->topupOf->id, 'loan_number' => $loan->topupOf->loan_number, 'status_label' => $loan->topupOf->status->label()] : null,
            'timeline' => $loan->auditLogs()->with('employee')->where('action', 'not like', 'Loan.%')->latest('id')->get()->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'from' => isset($log->before['status']) ? LoanStatus::tryFrom($log->before['status'])?->label() : null,
                'to' => isset($log->after['status']) ? LoanStatus::tryFrom($log->after['status'])?->label() : null,
                'context' => $log->context,
                'user' => $log->employee?->full_name ?? 'SYSTEM',
                'created_at' => $log->created_at?->toDateTimeString(),
            ])->values(),
            'customer_loans' => LoanResource::collection($customer->loans()->with('category')->latest('id')->get()),
            'customer_freeze' => $eligibility->freeze($customer),
            'customer_eligible' => $this->workflow->borrowingStatus($customer)['eligible'],
            'credit_assessment' => $this->assessments->forDisplay($loan),
        ]]);
    }

    /**
     * The latest write-off request of the loan (maker/checker) with the viewer's approval flags.
     *
     * @return array<string, mixed>|null
     */
    private function writeOffRequest(Loan $loan, Employee $viewer): ?array
    {
        $request = WriteOffRequest::where('loan_id', $loan->id)->with(['requester', 'approver', 'rejecter'])->latest('id')->first();
        if ($request === null) {
            return null;
        }
        $pending = $request->status === WriteOffRequest::PENDING;

        return [
            'id' => $request->id,
            'status' => $request->status,
            'reason' => $request->reason,
            'requested_by' => $request->requester?->full_name,
            'requested_at' => $request->created_at?->toDateTimeString(),
            'approved_by' => $request->approver?->full_name,
            'approved_at' => $request->approved_at?->toDateTimeString(),
            'rejected_by' => $request->rejecter?->full_name,
            'rejected_at' => $request->rejected_at?->toDateTimeString(),
            'rejection_reason' => $request->rejection_reason,
            ...app(SegregationOfDuties::class)->flags($request->requested_by, $viewer, $pending, $viewer->can('loans.write_off'), workflow: ApprovalPolicy::WRITE_OFFS),
        ];
    }

    /**
     * The pending reversal request of a repayment or of the loan's disbursement, with the viewer's approval flags.
     *
     * @return array<string, mixed>|null
     */
    private function pendingReversal(LoanTransaction|Loan $subject, Employee $viewer): ?array
    {
        $requests = app(ReversalRequests::class);
        $pending = $requests->pendingFor($subject);

        return $pending === null ? null : $requests->present($pending, $viewer, $viewer->can('reversals.approve'));
    }

    /**
     * Customer → Loan → Approval → Disbursement → source account → journal entry, from the loan's own records.
     *
     * @return array<string, mixed>|null
     */
    private function disbursementChain(Loan $loan): ?array
    {
        $disbursement = $loan->disbursements->firstWhere('status', LoanDisbursement::SUCCESS) ?? $loan->disbursements->last();
        if ($disbursement === null) {
            return null;
        }

        $approval = fn (string $action): ?array => ($log = $loan->auditLogs()->with('employee')->where('action', $action)->latest('id')->first())
            ? ['by' => $log->employee?->full_name ?? 'SYSTEM', 'at' => $log->created_at?->toDateTimeString(), 'context' => $log->context]
            : null;

        return [
            'customer' => ['id' => $loan->customer->id, 'name' => $loan->customer->full_name, 'code' => $loan->customer->customer_code],
            'loan' => ['id' => $loan->id, 'loan_number' => $loan->loan_number, 'reference_number' => $loan->reference_number, 'amount_approved' => (float) $loan->amount_approved],
            'manager_approval' => $approval('MANAGER_APPROVED'),
            'credit_approval' => $approval('CREDIT_APPROVED'),
            'disbursement' => [
                'id' => $disbursement->id,
                'batch_id' => $disbursement->batch_id,
                'channel' => $disbursement->channel,
                'status' => $disbursement->status,
                'amount' => (float) $disbursement->amount,
                'source_label' => $disbursement->sourceLabel(),
                'destination' => Account::LoanReceivable->label().' - '.$loan->loan_number,
                'provider_reference' => $disbursement->provider_reference,
                'requested_by' => $disbursement->requester?->full_name,
                'completed_at' => $disbursement->completed_at?->toDateTimeString(),
            ],
            'journal_entry' => $disbursement->journalEntry ? $this->presentEntry($disbursement->journalEntry) : null,
        ];
    }

    /**
     * The customer's loan account in the ledger: every journal entry posted for this loan, its repayments and its recoveries
     * after write-off, and the LOAN RECEIVABLE balance those postings leave.
     *
     * @return array{receivable_balance: float, entries: list<array<string, mixed>>}
     */
    private function receivableLedger(Loan $loan): array
    {
        $entries = JournalEntry::query()
            ->where('company_id', $loan->company_id)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $inner) => $inner->where('source_type', $loan->getMorphClass())->where('source_id', $loan->id))
                ->orWhere(fn (Builder $inner) => $inner->where('source_type', (new LoanTransaction)->getMorphClass())->whereIn('source_id', $loan->transactions()->select('id')))
                ->orWhere(fn (Builder $inner) => $inner->where('source_type', (new LoanRecovery)->getMorphClass())->whereIn('source_id', $loan->recoveries()->select('id'))))
            ->with('lines.account')
            ->orderBy('id')
            ->get();

        $receivable = $entries->flatMap->lines->filter(fn ($line): bool => $line->account?->key === Account::LoanReceivable);

        return [
            'receivable_balance' => round((float) $receivable->sum('debit') - (float) $receivable->sum('credit'), 2),
            'entries' => $entries->map(fn (JournalEntry $entry): array => $this->presentEntry($entry))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentEntry(JournalEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'reference' => $entry->reference,
            'description' => $entry->description,
            'entry_date' => $entry->entry_date?->toDateString(),
            'created_at' => $entry->created_at?->toDateTimeString(),
            'lines' => $entry->lines->map(fn ($line): array => [
                'key' => $line->account?->key?->value,
                'account' => $line->account?->name,
                'code' => $line->account?->code,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
            ])->values()->all(),
        ];
    }

    /**
     * POST /loans (Documents: "Officer anaomba mkopo → validate product rules, generate repayment plan → PENDING_MANAGER_APPROVAL").
     */
    public function store(LoanApplicationRequest $request): JsonResponse
    {
        $this->authorizeAny('loans.apply');
        $customer = Customer::findOrFail($request->integer('customer_id'));
        $this->assertBranchAccessible((int) $customer->branch_id);

        // Guarantors picked on the first form are checked before anything is written, then saved with the loan.
        $guarantors = $this->guarantors->resolveMany($customer, (array) $request->input('guarantors', []));

        try {
            $loan = DB::transaction(function () use ($customer, $request, $guarantors): Loan {
                $loan = $this->workflow->apply($customer, $request->loanData(), $this->currentEmployee());

                foreach ($guarantors as $index => $guarantor) {
                    $this->guarantors->attach($loan, $guarantor, "guarantors.{$index}.");
                }

                return $loan;
            });
        } catch (ValidationException $exception) {
            throw $this->liveFieldNames($exception);
        }

        return $this->message('Loan Application Registered successfully', 201, ['data' => new LoanResource($loan->load(['customer', 'branch', 'category']))]);
    }

    /**
     * Edit loan (live edit_loan / modify_loanapplication). A returned application is resubmitted to the manager.
     */
    public function update(LoanApplicationRequest $request, Loan $loan): JsonResponse
    {
        $this->authorizeAny('loans.apply');
        $this->ensureVisible($loan);
        $this->assertCategoryAvailable($request->integer('category_id'), (int) $loan->branch_id);

        $loan = $this->workflow->update($loan, $request->loanData(), $this->currentEmployee());

        return $this->message('Loan Updated successfully', 200, ['data' => new LoanResource($loan->load(['customer', 'branch', 'category']))]);
    }

    /**
     * Live delete_loan. Handwritten note: nothing can be deleted once approved (no rollback).
     */
    public function destroy(Loan $loan): JsonResponse
    {
        $this->authorizeAny('loans.apply', 'loans.approve_manager');
        $this->ensureVisible($loan);

        if (! in_array($loan->status, [LoanStatus::PendingManagerApproval, LoanStatus::Returned, LoanStatus::Rejected], true)) {
            return $this->message('Only loans that have not been approved can be deleted', 422);
        }

        $this->workflow->record($loan, 'DELETED', $loan->status, $this->currentEmployee(), ['loan_number' => $loan->loan_number]);
        $loan->guarantors()->update(['loan_id' => null]);
        $loan->delete();

        return $this->message('Loan Deleted successfully');
    }

    /**
     * Import Guarantor options on the first application form, before the loan exists.
     */
    public function guarantorCandidates(Customer $customer): JsonResponse
    {
        $this->authorizeAny('loans.apply');
        $this->assertBranchAccessible((int) $customer->branch_id);

        return response()->json(['data' => $this->guarantors->candidates($customer)]);
    }

    /**
     * Loan categories for the application form: only the ACTIVE loan categories (customer type active, assigned to the
     * customer's branch) of the customer's customer type, as "NAME / from - to". A customer without a customer type gets
     * none, with the reason in `eligibility`.
     */
    public function categories(Customer $customer, CustomerEligibility $eligibility): JsonResponse
    {
        $this->authorizeAny('loans.apply', 'loans.view');
        $this->assertBranchAccessible((int) $customer->branch_id);
        $status = $this->workflow->borrowingStatus($customer);
        $type = $customer->customerCategory;

        $categories = $eligibility->availableLoanCategories($customer)
            ->map(fn (LoanCategory $category): array => [
                'value' => (string) $category->id,
                'label' => $category->option_label,
                'allowed' => true,
                'amount_from' => (float) $category->amount_from,
                'amount_to' => (float) $category->amount_to,
                'interest_rate' => (float) $category->interest_rate,
                'formula' => $category->formula,
                'duration' => $category->duration?->value,
                'duration_label' => $category->duration?->label(),
                'repayment_from' => $category->repayment_from,
                'repayment_to' => $category->repayment_to,
                'fee_deduct' => $category->fee_deduct,
                'requires_mandate' => (bool) $category->requires_mandate,
                'topup_percent' => (float) $category->topup_percent,
                'freeze_time_days' => (int) $category->freeze_time_days,
            ]);

        return response()->json([
            'data' => $categories->values(),
            'customer_type' => $type ? ['id' => $type->id, 'code' => $type->code, 'name' => $type->name] : null,
            'groups' => Group::where('company_id', $customer->company_id)->orderBy('name')->get()->map(fn (Group $group): array => ['value' => (string) $group->id, 'label' => $group->name])->values(),
            'eligibility' => $status,
        ]);
    }

    /**
     * Formula preview for the application form (LoanCalculator + product fee; no insurance, §47).
     */
    public function preview(Request $request, LoanCalculator $calculator): JsonResponse
    {
        $this->authorizeAny('loans.apply', 'loans.view');
        $request->merge(['how_loan' => preg_replace('/[^\d.]/', '', (string) $request->input('how_loan'))]);
        $validated = $request->validate([
            'category_id' => ['required', Rule::exists('loan_categories', 'id')->where('company_id', $this->currentEmployee()->company_id)],
            'how_loan' => ['required', 'numeric', 'min:1'],
            'session' => ['required', 'integer', 'min:1'],
            'rate' => ['required', Rule::in(['SIMPLE', 'FLATRATE', 'REDUCING'])],
            'fee_status' => ['nullable', Rule::in(['YES', 'NO'])],
        ]);

        $category = LoanCategory::findOrFail($validated['category_id']);
        $principal = (float) $validated['how_loan'];
        $figures = $calculator->calculate($validated['rate'], $principal, (float) $category->interest_rate, (int) $validated['session']);
        $fee = $category->feeFor($principal);
        $deductFee = ($validated['fee_status'] ?? 'YES') === 'YES';
        $start = now()->toImmutable();
        $duration = $category->duration ?? Duration::Monthly;

        return response()->json(['data' => [
            'principal' => $principal,
            'interest_rate' => (float) $category->interest_rate,
            'interest' => $figures['interest'],
            'total' => $figures['total'],
            // §47: new loans carry no insurance.
            'insurance' => 0.0,
            'restoration' => $figures['restoration'],
            'loan_fee' => $fee,
            'take_home' => round($principal - ($deductFee ? $fee : 0), 2),
            'duration_label' => $duration->label(),
            'end_date' => $duration->addPeriods($start, (int) $validated['session'])->toDateString(),
            'schedule' => collect(range(1, min(60, (int) $validated['session'])))->map(fn (int $session): array => [
                'session' => $session,
                'due_date' => $duration->addPeriods($start, $session)->toDateString(),
                'amount' => $figures['restoration'],
            ])->values(),
            'errors' => array_values(array_filter([
                $principal < (float) $category->amount_from || $principal > (float) $category->amount_to ? "Loan amount must be between {$category->level_label}" : null,
                $validated['session'] < $category->repayment_from || $validated['session'] > $category->repayment_to ? "Number of repayments must be between {$category->repayment_from} - {$category->repayment_to}" : null,
            ])),
        ]]);
    }

    /**
     * Live Loan Withdrawal report: loans disbursed in a period (default today), grouped by duration on the page.
     * The live "Method" column is the disbursement channel.
     */
    public function withdrawals(Request $request): JsonResponse
    {
        $this->authorizeAny('loans.view');
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'loan_status' => ['nullable', 'string'],
        ]);

        $statuses = match ($request->input('loan_status')) {
            'ACTIVE' => [LoanStatus::Active, LoanStatus::Overdue],
            'DONE' => [LoanStatus::Closed],
            'DEFALT' => [LoanStatus::Default],
            'PENDING', 'APROVED', 'DISBURSED' => [],
            default => LoanStatus::disbursed(),
        };

        if ($statuses === []) {
            return response()->json(['data' => []]);
        }

        $query = $this->scoped(Loan::query())
            ->whereNotNull('withdrawn_at')
            ->status(...$statuses)
            ->with(['customer', 'branch', 'category'])
            ->latest('withdrawn_at');

        if (! $request->filled('from') && ! $request->filled('to')) {
            $query->whereDate('withdrawn_at', today());
        }
        $this->applyFilters($query, $request, 'withdrawn_at');

        return response()->json(['data' => LoanResource::collection($query->get())]);
    }

    private function assertCategoryAvailable(int $categoryId, int $branchId): void
    {
        $available = LoanCategory::whereKey($categoryId)->whereHas('branches', fn (Builder $query) => $query->whereKey($branchId))->exists();

        if (! $available) {
            throw ValidationException::withMessages(['category_id' => 'This loan category is not available for the customer branch']);
        }
    }

    /**
     * LoanService validates with model names; report them on the live form fields.
     */
    private function liveFieldNames(ValidationException $exception): ValidationException
    {
        $map = ['amount_applied' => 'how_loan', 'sessions' => 'session', 'loan_category_id' => 'category_id'];
        $errors = [];
        foreach ($exception->errors() as $field => $messages) {
            $errors[$map[$field] ?? $field] = $messages;
        }

        return ValidationException::withMessages($errors);
    }
}

<?php

namespace App\Services\Hrm;

use App\Enums\Account;
use App\Enums\StaffCreditStatus;
use App\Models\ApprovalPolicy;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\StaffLoan;
use App\Models\StaffLoanPayment;
use App\Models\StaffSalaryAdvance;
use App\Models\StaffSalaryAdvanceCategory;
use App\Services\AccessControl;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Ledger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Staff loans and staff salary advances (spec §28–§33, §48, §49, §56).
 *
 * Workflow: SUBMITTED (the staff member for themselves, or HR on their behalf) → HR APPROVED (HR review) → FINANCE APPROVED
 * (Finance, payroll.pay) → DISBURSED (Finance pays from the Fund Account) → REPAYING (payroll / cash recovery back into the
 * Fund Account) → COMPLETED. REJECTED before disbursement.
 *
 * §32 conflict of interest: when the beneficiary holds `hrm.manage` (an HR user benefiting personally) the HR review is
 * replaced by an ADMIN approval (role admin / super_admin): HR → Admin → Finance. The stage is fixed when the request is
 * submitted ({@see self::reviewStageFor()}).
 *
 * Rule 6 (segregation of duties, {@see SegregationOfDuties}): nobody approves or pays a request they submitted or that benefits
 * them (the Super Admin excepted), and Finance's approval / payment is by someone other than the reviewer. The same Finance
 * user may approve and then disburse. Legacy rows without a recorded requester skip that part.
 */
class StaffCredit
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly StaffFund $fund,
        private readonly SegregationOfDuties $duties,
        private readonly AccessControl $access,
    ) {}

    /**
     * §32: an HR user's own request goes to Admin instead of HR.
     */
    public function reviewStageFor(Employee $beneficiary): string
    {
        return $this->access->can($beneficiary, 'hrm.manage') ? StaffCreditStatus::REVIEW_ADMIN : StaffCreditStatus::REVIEW_HR;
    }

    /**
     * Admin approver of §32 self-requests: a staff login with the Admin or Super Admin role.
     */
    public function isAdmin(Employee $employee): bool
    {
        return in_array($employee->role?->key, ['admin', 'super_admin'], true) && ! $employee->isShareholderAccount();
    }

    /**
     * @param  array{branch_id: int, employee_id: int, staff_loan_category_id: int, amount_applied: float, duration: string, sessions: int, reason: string}  $data
     */
    public function submitLoan(array $data, Employee $beneficiary, Employee $requester): StaffLoan
    {
        $loan = StaffLoan::create($data + [
            'company_id' => $beneficiary->company_id,
            'status' => StaffCreditStatus::Submitted->value,
            'review_stage' => $this->reviewStageFor($beneficiary),
            'requested_by' => $requester->id,
        ]);
        $this->audit($loan, $requester, null, StaffCreditStatus::Submitted->value);

        return $loan;
    }

    public function submitAdvance(StaffSalaryAdvanceCategory $category, float $amount, int $branchId, Employee $beneficiary, Employee $requester): StaffSalaryAdvance
    {
        $advance = StaffSalaryAdvance::create([
            'company_id' => $beneficiary->company_id,
            'branch_id' => $branchId,
            'employee_id' => $beneficiary->id,
            'staff_salary_advance_category_id' => $category->id,
            'amount' => $amount,
            'fee' => $category->fee,
            'status' => StaffCreditStatus::Submitted->value,
            'review_stage' => $this->reviewStageFor($beneficiary),
            'requested_by' => $requester->id,
        ]);
        $this->audit($advance, $requester, null, StaffCreditStatus::Submitted->value);

        return $advance;
    }

    /**
     * HR review (or Admin review for an HR user's own request). For a loan the live rule fixes the amounts: the full amount
     * applied is approved; Loan + interest = amount × (1 + rate%), restoration = total ÷ number of repayments.
     */
    public function review(StaffLoan|StaffSalaryAdvance $credit, Employee $reviewer): void
    {
        $this->assertStatus($credit, [StaffCreditStatus::Submitted->value]);
        $this->assertStagePermission($credit, $reviewer);
        $this->duties->assertCanApprove($this->beneficiaries($credit), $reviewer, 'staff credit', workflow: ApprovalPolicy::STAFF_CREDIT);

        $status = $credit->review_stage === StaffCreditStatus::REVIEW_ADMIN ? StaffCreditStatus::AdminApproved : StaffCreditStatus::HrApproved;
        $values = ['status' => $status->value, 'approved_by' => $reviewer->id, 'approved_at' => now()];

        if ($credit instanceof StaffLoan) {
            $credit->loadMissing('category');
            $amount = (float) $credit->amount_applied;
            $total = round($amount * (1 + (float) $credit->category->interest_rate / 100), 2);
            $values += [
                'amount_approved' => $amount,
                'total_payable' => $total,
                'restoration' => round($total / max(1, $credit->sessions), 2),
                'fee' => $credit->category->fee,
            ];
        }

        $this->transition($credit, $reviewer, $values);
    }

    /**
     * Finance approval (§29 "Finance performs the financial approval"), separate from the payment.
     */
    public function financeApprove(StaffLoan|StaffSalaryAdvance $credit, Employee $approver): void
    {
        $this->assertStatus($credit, StaffCreditStatus::reviewed());
        $this->assertFinance($approver);
        $this->assertFinanceSeparation($credit, $approver);

        $this->transition($credit, $approver, ['status' => StaffCreditStatus::FinanceApproved->value, 'finance_approved_by' => $approver->id, 'finance_approved_at' => now()]);
    }

    /**
     * Rejection before disbursement: the review-stage approver while submitted, Finance at any stage before payment.
     */
    public function reject(StaffLoan|StaffSalaryAdvance $credit, Employee $employee, ?string $reason = null): void
    {
        $this->assertStatus($credit, StaffCreditStatus::awaitingDisbursement(), 'can not be rejected');

        $mayReject = $this->access->can($employee, 'payroll.pay')
            || ($credit->status === StaffCreditStatus::Submitted->value && $this->stageBlockedReason($credit, $employee) === null);
        if (! $mayReject) {
            throw new AccessDeniedHttpException('You do not have permission to perform this action.');
        }

        $this->transition($credit, $employee, ['status' => StaffCreditStatus::Rejected->value, 'rejected_by' => $employee->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);
    }

    /**
     * Dr Staff Loan (principal) / Cr Staff Fund A/C (principal − fee) / Cr Staff Fund (fee, inferred: the category charge is
     * kept by the fund). The Fund Account decreases by the actual amount paid out (§56).
     */
    public function disburseLoan(StaffLoan $loan, Employee $payer): void
    {
        $this->assertStatus($loan, [StaffCreditStatus::FinanceApproved->value]);
        $this->assertFinance($payer);
        $this->assertFinanceSeparation($loan, $payer);

        $principal = (float) $loan->amount_approved;
        $fee = min((float) $loan->fee, $principal);
        $this->fund->assertAvailable((int) $loan->company_id, $principal - $fee);

        DB::transaction(function () use ($loan, $payer, $principal, $fee): void {
            $this->transition($loan, $payer, ['status' => StaffCreditStatus::Disbursed->value, 'disbursed_by' => $payer->id, 'disbursed_at' => now()]);
            $loan->loadMissing('employee');

            $this->ledger->journal($loan->company_id, "Staff loan - {$loan->employee->full_name}", [
                ['account' => Account::StaffLoanReceivable, 'employee' => $loan->employee_id, 'debit' => $principal],
                ['account' => Account::StaffFundCash, 'credit' => $principal - $fee],
                ['account' => Account::StaffFund, 'credit' => $fee],
            ], $loan, employee: $payer);
        });
    }

    /**
     * §30: a staff salary advance is paid ONLY from the Fund Account. The category charge (fee) is withheld from the amount
     * paid out and kept by the fund. (Advances paid from the company account before this rule keep recovering to it.)
     */
    public function disburseAdvance(StaffSalaryAdvance $advance, Employee $payer): void
    {
        $this->assertStatus($advance, [StaffCreditStatus::FinanceApproved->value]);
        $this->assertFinance($payer);
        $this->assertFinanceSeparation($advance, $payer);

        $amount = (float) $advance->amount;
        $fee = min((float) $advance->fee, $amount);
        $this->fund->assertAvailable((int) $advance->company_id, $amount - $fee);

        DB::transaction(function () use ($advance, $payer, $amount, $fee): void {
            $this->transition($advance, $payer, ['status' => StaffCreditStatus::Disbursed->value, 'source_account' => Account::StaffFundCash->value, 'disbursed_by' => $payer->id, 'disbursed_at' => now()]);
            $advance->loadMissing('employee');

            $this->ledger->journal($advance->company_id, "Staff salary advance - {$advance->employee->full_name}", [
                ['account' => Account::StaffAdvanceReceivable, 'employee' => $advance->employee_id, 'debit' => $amount],
                ['account' => Account::StaffFundCash, 'credit' => $amount - $fee],
                ['account' => Account::StaffFund, 'credit' => $fee],
            ], $advance, employee: $payer);
        });
    }

    /**
     * Cash repayment ("Deposit") into the Staff Fund A/C: principal share reduces the loan, interest share is fund income.
     */
    public function repayLoan(StaffLoan $loan, float $amount, Employee $recorder): StaffLoanPayment
    {
        $this->assertStatus($loan, StaffCreditStatus::recovering(), 'is not active');

        if ($amount > $loan->remainingAmount() + 0.001) {
            throw ValidationException::withMessages(['amount' => 'Amount is more than the remaining loan of '.number_format($loan->remainingAmount())]);
        }

        return DB::transaction(function () use ($loan, $amount, $recorder): StaffLoanPayment {
            $payment = $this->recordLoanPayment($loan, $amount);
            [$principal, $interest] = $this->splitLoanPayment($loan, $amount);

            $this->ledger->journal($loan->company_id, 'Staff loan repayment', [
                ['account' => Account::StaffFundCash, 'debit' => $amount],
                ['account' => Account::StaffLoanReceivable, 'employee' => $loan->employee_id, 'credit' => $principal],
                ['account' => Account::StaffFund, 'credit' => $interest],
            ], $payment, employee: $recorder);

            return $payment;
        });
    }

    /**
     * The next workflow step for this credit and whether the employee may take it now.
     *
     * @return array{action: string|null, permitted: bool, blocked_reason: string|null}
     */
    public function nextStep(StaffLoan|StaffSalaryAdvance $credit, Employee $employee): array
    {
        $action = match (true) {
            $credit->status === StaffCreditStatus::Submitted->value => $credit->review_stage === StaffCreditStatus::REVIEW_ADMIN ? 'admin_approve' : 'approve',
            in_array($credit->status, StaffCreditStatus::reviewed(), true) => 'finance_approve',
            $credit->status === StaffCreditStatus::FinanceApproved->value => 'disburse',
            default => null,
        };

        if ($action === null) {
            return ['action' => null, 'permitted' => false, 'blocked_reason' => null];
        }

        $permitted = $action === 'approve' || $action === 'admin_approve'
            ? $this->stageBlockedReason($credit, $employee) === null
            : $this->access->can($employee, 'payroll.pay');

        return ['action' => $action, 'permitted' => $permitted, 'blocked_reason' => $permitted ? $this->stepBlockedReason($credit, $employee) : null];
    }

    /**
     * Why the employee may not take the next approval step (rule 6), or null.
     */
    public function stepBlockedReason(StaffLoan|StaffSalaryAdvance $credit, Employee $employee): ?string
    {
        $own = $this->duties->blockedReason($this->beneficiaries($credit), $employee, workflow: ApprovalPolicy::STAFF_CREDIT);

        return match (true) {
            $credit->status === StaffCreditStatus::Submitted->value => $own,
            in_array($credit->status, [...StaffCreditStatus::reviewed(), StaffCreditStatus::FinanceApproved->value], true) => $own
                ?? $this->duties->blockedReason($credit->approved_by, $employee, SegregationOfDuties::STAGE_MESSAGE, workflow: ApprovalPolicy::STAFF_CREDIT),
            default => null,
        };
    }

    public function recordLoanPayment(StaffLoan $loan, float $amount): StaffLoanPayment
    {
        $payment = $loan->payments()->create(['amount' => $amount, 'paid_on' => now()]);

        $completed = (float) $loan->total_payable - (float) $loan->payments()->sum('amount') <= 0.001;
        $loan->update($completed
            ? ['status' => StaffCreditStatus::Completed->value, 'completed_at' => now()]
            : ['status' => StaffCreditStatus::Repaying->value]);

        return $payment;
    }

    /**
     * Status of an advance after a salary recovery.
     *
     * @return array{recovered_amount: float, status: string, completed_at?: Carbon}
     */
    public function advanceRecoveryValues(StaffSalaryAdvance $advance, float $recovered): array
    {
        return $recovered >= (float) $advance->amount
            ? ['recovered_amount' => $recovered, 'status' => StaffCreditStatus::Completed->value, 'completed_at' => now()]
            : ['recovered_amount' => $recovered, 'status' => StaffCreditStatus::Repaying->value];
    }

    /**
     * @return array{0: float, 1: float} principal share, interest share
     */
    public function splitLoanPayment(StaffLoan $loan, float $amount): array
    {
        $total = (float) $loan->total_payable;
        $principal = $total > 0 ? round($amount * (float) $loan->amount_approved / $total, 2) : $amount;

        return [$principal, round($amount - $principal, 2)];
    }

    /**
     * @return list<int|null>
     */
    private function beneficiaries(StaffLoan|StaffSalaryAdvance $credit): array
    {
        return [$credit->requested_by, $credit->employee_id];
    }

    /**
     * Why the employee does not hold the review-stage role (HR stage: hrm.manage / payroll.approve; Admin stage: Admin role).
     */
    private function stageBlockedReason(StaffLoan|StaffSalaryAdvance $credit, Employee $employee): ?string
    {
        if ($credit->review_stage === StaffCreditStatus::REVIEW_ADMIN) {
            return $this->isAdmin($employee) ? null : 'This request benefits an HR user, so an Admin must approve it.';
        }

        return $this->access->can($employee, 'hrm.manage') || $this->access->can($employee, 'payroll.approve')
            ? null
            : 'You do not have permission to perform this action.';
    }

    private function assertStagePermission(StaffLoan|StaffSalaryAdvance $credit, Employee $employee): void
    {
        $reason = $this->stageBlockedReason($credit, $employee);

        if ($reason !== null) {
            throw new AccessDeniedHttpException($reason);
        }
    }

    private function assertFinance(Employee $employee): void
    {
        if (! $this->access->can($employee, 'payroll.pay')) {
            throw new AccessDeniedHttpException('You do not have permission to perform this action.');
        }
    }

    /**
     * Finance is neither the requester nor the beneficiary, nor the employee who took the review stage.
     */
    private function assertFinanceSeparation(StaffLoan|StaffSalaryAdvance $credit, Employee $employee): void
    {
        $this->duties->assertCanApprove($this->beneficiaries($credit), $employee, 'staff credit', workflow: ApprovalPolicy::STAFF_CREDIT);
        $this->duties->assertCanApprove($credit->approved_by, $employee, 'staff credit', SegregationOfDuties::STAGE_MESSAGE, workflow: ApprovalPolicy::STAFF_CREDIT);
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertStatus(StaffLoan|StaffSalaryAdvance $credit, array $expected, ?string $message = null): void
    {
        if (! in_array($credit->status, $expected, true)) {
            $label = $credit instanceof StaffLoan ? 'Staff loan' : 'Salary advance';
            $current = StaffCreditStatus::tryFrom($credit->status)?->label() ?? $credit->status;

            throw ValidationException::withMessages(['status' => $message !== null ? "{$label} {$message}" : "{$label} is {$current}"]);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function transition(StaffLoan|StaffSalaryAdvance $credit, Employee $actor, array $values): void
    {
        $before = $credit->status;
        $credit->update($values);
        $this->audit($credit, $actor, $before, $credit->status);
    }

    /**
     * §49: every status change is auditable.
     */
    private function audit(StaffLoan|StaffSalaryAdvance $credit, Employee $actor, ?string $before, string $after): void
    {
        AuditLog::create([
            'company_id' => $credit->company_id,
            'employee_id' => $actor->id,
            'action' => class_basename($credit).'.'.$after,
            'auditable_type' => $credit->getMorphClass(),
            'auditable_id' => $credit->id,
            'before' => $before === null ? null : ['status' => $before],
            'after' => ['status' => $after],
            'ip_address' => request()->ip(),
        ]);
    }
}

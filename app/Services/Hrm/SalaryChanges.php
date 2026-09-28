<?php

namespace App\Services\Hrm;

use App\Models\ApprovalPolicy;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\SalaryChangeRequest;
use App\Services\AccessControl;
use App\Services\Approvals\SegregationOfDuties;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Spec §32 salary changes: HR proposes a change of an existing salary (amount, salary type or commission eligibility) and
 * Finance (payroll.pay) approves it. When the salary is the proposer's own, or belongs to an HR user (hrm.manage), an Admin
 * approves instead of Finance. Nobody approves a change they proposed or that changes their own salary (the Super Admin
 * excepted, {@see SegregationOfDuties}). The first salary set up for a new employee is saved directly, unless it is the
 * proposer's own. Every step is written to the audit log.
 */
class SalaryChanges
{
    /**
     * Salary fields that change what the employee is paid.
     */
    public const PAY_FIELDS = ['salary', 'salary_type', 'commission_eligible'];

    public const LOCKED_MESSAGE = 'Salary can not be changed after payroll approval';

    public function __construct(
        private readonly AccessControl $access,
        private readonly SegregationOfDuties $duties,
        private readonly StaffCredit $credit,
    ) {}

    /**
     * Whether saving these values must go through approval instead of being saved directly.
     *
     * @param  array<string, mixed>  $values
     */
    public function requiresApproval(Employee $employee, array $values, Employee $proposer): bool
    {
        if ($employee->is($proposer)) {
            return true;
        }

        $current = $employee->salaryInfo;
        if ($current === null) {
            return false;
        }

        return (float) $current->salary !== (float) $values['salary']
            || (string) $current->salary_type !== (string) $values['salary_type']
            || (bool) $current->commission_eligible !== (bool) $values['commission_eligible'];
    }

    /**
     * STAFF COMMISSION §16: the salary can not change while the employee is on an approved payroll that has not been paid.
     *
     * @param  array<string, mixed>  $values
     */
    public function isLocked(Employee $employee, array $values): bool
    {
        return $employee->salaryInfo !== null
            && (float) $employee->salaryInfo->salary !== (float) $values['salary']
            && DB::table('payroll_items')->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_items.payroll_run_id')
                ->where('payroll_items.employee_id', $employee->id)->where('payroll_runs.status', 'approved')->exists();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function propose(Employee $employee, array $values, Employee $proposer, ?string $reason = null): SalaryChangeRequest
    {
        if (SalaryChangeRequest::where('employee_id', $employee->id)->where('status', SalaryChangeRequest::STATUS_SUBMITTED)->exists()) {
            throw ValidationException::withMessages(['salary' => 'A salary change for this employee is already waiting for approval']);
        }

        $change = SalaryChangeRequest::create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'current_values' => $employee->salaryInfo?->only(['salary', 'account_name', 'account_number', 'fee', 'salary_type', 'commission_eligible', 'payment_method']),
            'proposed_values' => $values,
            'status' => SalaryChangeRequest::STATUS_SUBMITTED,
            'approval_stage' => $employee->is($proposer) || $this->access->can($employee, 'hrm.manage') ? SalaryChangeRequest::STAGE_ADMIN : SalaryChangeRequest::STAGE_FINANCE,
            'reason' => $reason,
            'requested_by' => $proposer->id,
        ]);

        $this->audit('EmployeeSalary.change_requested', $change, $proposer, $change->current_values, $values);

        return $change;
    }

    public function approve(SalaryChangeRequest $change, Employee $approver): void
    {
        $this->assertOpen($change);
        $this->assertStagePermission($change, $approver);
        $this->duties->assertCanApprove([$change->requested_by, $change->employee_id], $approver, 'salary change', workflow: ApprovalPolicy::PAYROLL);

        $employee = $change->employee;
        if ($this->isLocked($employee, $change->proposed_values)) {
            throw ValidationException::withMessages(['salary' => self::LOCKED_MESSAGE]);
        }

        DB::transaction(function () use ($change, $approver, $employee): void {
            $before = $employee->salaryInfo?->only(['salary', 'salary_type', 'commission_eligible', 'payment_method', 'account_number']);
            $salary = $employee->salaryInfo()->updateOrCreate(['employee_id' => $employee->id], $change->proposed_values);
            $change->update(['status' => SalaryChangeRequest::STATUS_APPROVED, 'approved_by' => $approver->id, 'approved_at' => now()]);

            $this->audit('EmployeeSalary.change_approved', $change, $approver, $before, $salary->only(['salary', 'salary_type', 'commission_eligible', 'payment_method', 'account_number']));
        });
    }

    public function reject(SalaryChangeRequest $change, Employee $employee, ?string $reason = null): void
    {
        $this->assertOpen($change);
        $this->assertStagePermission($change, $employee);

        $change->update(['status' => SalaryChangeRequest::STATUS_REJECTED, 'rejected_by' => $employee->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);
        $this->audit('EmployeeSalary.change_rejected', $change, $employee, null, ['reason' => $reason]);
    }

    /**
     * Whether the viewer holds the approval stage and, if so, why rule 6 still blocks them.
     *
     * @return array{can_approve: bool, approve_blocked_reason: string|null}
     */
    public function flags(SalaryChangeRequest $change, Employee $viewer): array
    {
        if ($change->status !== SalaryChangeRequest::STATUS_SUBMITTED || $this->stageBlockedReason($change, $viewer) !== null) {
            return ['can_approve' => false, 'approve_blocked_reason' => null];
        }

        $reason = $this->duties->blockedReason([$change->requested_by, $change->employee_id], $viewer, workflow: ApprovalPolicy::PAYROLL);

        return ['can_approve' => $reason === null, 'approve_blocked_reason' => $reason];
    }

    private function stageBlockedReason(SalaryChangeRequest $change, Employee $employee): ?string
    {
        if ($change->approval_stage === SalaryChangeRequest::STAGE_ADMIN) {
            return $this->credit->isAdmin($employee) ? null : 'This salary change is for an HR user or the proposer, so an Admin must approve it.';
        }

        return $this->access->can($employee, 'payroll.pay') ? null : 'You do not have permission to perform this action.';
    }

    private function assertStagePermission(SalaryChangeRequest $change, Employee $employee): void
    {
        $reason = $this->stageBlockedReason($change, $employee);

        if ($reason !== null) {
            throw new AccessDeniedHttpException($reason);
        }
    }

    private function assertOpen(SalaryChangeRequest $change): void
    {
        if ($change->status !== SalaryChangeRequest::STATUS_SUBMITTED) {
            throw ValidationException::withMessages(['status' => 'Salary change is already '.$change->status]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(string $action, SalaryChangeRequest $change, Employee $actor, ?array $before, ?array $after): void
    {
        AuditLog::create([
            'company_id' => $change->company_id,
            'employee_id' => $actor->id,
            'action' => $action,
            'auditable_type' => (new Employee)->getMorphClass(),
            'auditable_id' => $change->employee_id,
            'before' => $before,
            'after' => $after,
            'context' => ['salary_change_request_id' => $change->id, 'approval_stage' => $change->approval_stage],
            'ip_address' => request()->ip(),
        ]);
    }
}

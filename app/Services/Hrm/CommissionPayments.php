<?php

namespace App\Services\Hrm;

use App\Enums\Account;
use App\Enums\TransactionType;
use App\Models\AccountingPeriod;
use App\Models\ApprovalPolicy;
use App\Models\AuditLog;
use App\Models\CommissionAllocation;
use App\Models\Employee;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Commission payment flow (spec §21 / §22 / §23 / §49 / §57). Commission is no longer a payroll column: payroll pays salary +
 * allowances − salary deductions, and each employee's commission of a closed period is paid on its own, on whatever date
 * Finance pays it — the payment date never changes the commission period.
 *
 *   Month closes → CALCULATED ({@see CommissionEngine::calculate()}; staff can see it)
 *   → HR finalises the figures: AWAITING PAYMENT REQUEST (the period's commission can no longer be recalculated)
 *   → HR requests payment: PAYMENT REQUESTED (a request may finalise a calculated row in the same step)
 *   → Finance reviews and approves: FINANCE APPROVED (or rejects it back to awaiting request, with a reason)
 *   → Finance pays: PAID.
 *
 * HR = `payroll.approve`, Finance = `payroll.pay` (Finance is the final approver). Rule 6: the HR employee who requested the
 * payment — and the employee receiving the commission — can neither approve nor pay it; the Super Admin may
 * ({@see SegregationOfDuties}, workflow {@see ApprovalPolicy::PAYROLL}).
 *
 * Payment journal (one per employee commission, dated the payment date, source = the allocation):
 *   Dr COMMISSION PAYABLE (the branch/employee accounts the profit allocation credited)   gross commission
 *   Cr paying account (branch INTEREST A/C of the allocation, or COMPANY ACCOUNT)          gross commission
 *   negligence recovered (§23): Cr WRITE-OFF EXPENSE / Dr PRINCIPAL A/C                    recovered amount
 * so the employee receives gross − recovered and the recovered part goes to the PRINCIPAL A/C, exactly as the payroll did before.
 * Allocations without a profit-allocation journal (legacy June 2026 rule) are recognised as COMMISSION EXPENSE instead.
 *
 * Profit is untouched by the payment (the allocation already moved it to COMMISSION PAYABLE), so the dividend base — which
 * subtracts calculated commission — is the same before and after payment.
 *
 * Allocations carried by a payroll run (status `payroll`, legacy) never enter this flow.
 */
class CommissionPayments
{
    public const PAYING_INTEREST = 'interest';

    public const PAYING_COMPANY = 'company';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly CommissionEngine $engine,
        private readonly NegligenceDeductions $negligence,
        private readonly SegregationOfDuties $duties,
    ) {}

    /**
     * Allocations of a company for the workflow screens: one closed month, or an explicit id list, optionally limited to branches
     * and an employee.
     *
     * @param  list<int>|null  $ids
     * @param  list<int>|null  $branchIds
     * @return Collection<int, CommissionAllocation>
     */
    public function select(int $companyId, ?CarbonImmutable $month, ?array $ids = null, ?array $branchIds = null, ?int $employeeId = null): Collection
    {
        $period = $month === null ? null : $this->engine->closedPeriod($companyId, $month);
        if ($month !== null && $period === null) {
            return collect();
        }

        return CommissionAllocation::query()
            ->where('company_id', $companyId)
            ->when($period !== null, fn ($query) => $query->where('accounting_period_id', $period->id))
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->when($employeeId !== null, fn ($query) => $query->where('employee_id', $employeeId))
            ->with(['period', 'employee', 'branch', 'finalizer', 'requester', 'approver', 'rejecter', 'payer', 'payingBranch', 'paymentJournalEntry', 'payrollRun', 'negligenceRecoveries'])
            ->orderBy('accounting_period_id')
            ->orderBy('branch_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * HR finalises calculated commission: AWAITING PAYMENT REQUEST. Locks the period's commission against recalculation.
     *
     * @param  Collection<int, CommissionAllocation>  $allocations
     *
     * @throws ValidationException
     */
    public function finalize(Collection $allocations, Employee $hr): int
    {
        return $this->transition($allocations, [CommissionAllocation::STATUS_CALCULATED], 'finalised', function (CommissionAllocation $row) use ($hr): void {
            $row->update(['payment_status' => CommissionAllocation::STATUS_AWAITING_REQUEST, 'finalized_by' => $hr->id, 'finalized_at' => now()]);
        });
    }

    /**
     * HR requests payment of commission with an amount to pay: PAYMENT REQUESTED. A still calculated row is finalised in the same
     * step. Rows with nothing to pay (zero commission) stay where they are.
     *
     * @param  Collection<int, CommissionAllocation>  $allocations
     *
     * @throws ValidationException
     */
    public function request(Collection $allocations, Employee $hr): int
    {
        $payable = $allocations->filter(fn (CommissionAllocation $row): bool => (float) $row->amount > 0);

        return $this->transition($payable, [CommissionAllocation::STATUS_CALCULATED, CommissionAllocation::STATUS_AWAITING_REQUEST], 'payment requested', function (CommissionAllocation $row) use ($hr): void {
            $row->update([
                'payment_status' => CommissionAllocation::STATUS_REQUESTED,
                'finalized_by' => $row->finalized_by ?? $hr->id,
                'finalized_at' => $row->finalized_at ?? now(),
                'requested_by' => $hr->id,
                'requested_at' => now(),
            ]);
        });
    }

    /**
     * Finance reviews and approves requested commission: FINANCE APPROVED.
     *
     * @param  Collection<int, CommissionAllocation>  $allocations
     *
     * @throws ValidationException
     * @throws AccessDeniedHttpException
     */
    public function approve(Collection $allocations, Employee $finance): int
    {
        $this->assertSegregation($allocations, $finance, 'approve');

        return $this->transition($allocations, [CommissionAllocation::STATUS_REQUESTED], 'approved', function (CommissionAllocation $row) use ($finance): void {
            $row->update(['payment_status' => CommissionAllocation::STATUS_FINANCE_APPROVED, 'approved_by' => $finance->id, 'approved_at' => now()]);
        });
    }

    /**
     * Finance rejects a requested (or approved, not yet paid) commission payment back to AWAITING PAYMENT REQUEST, with a reason.
     *
     * @param  Collection<int, CommissionAllocation>  $allocations
     *
     * @throws ValidationException
     * @throws AccessDeniedHttpException
     */
    public function reject(Collection $allocations, Employee $finance, string $reason): int
    {
        $this->assertSegregation($allocations, $finance, 'reject');

        return $this->transition($allocations, [CommissionAllocation::STATUS_REQUESTED, CommissionAllocation::STATUS_FINANCE_APPROVED], 'rejected', function (CommissionAllocation $row) use ($finance, $reason): void {
            $row->update([
                'payment_status' => CommissionAllocation::STATUS_AWAITING_REQUEST,
                'rejected_by' => $finance->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
                'requested_by' => null,
                'requested_at' => null,
                'approved_by' => null,
                'approved_at' => null,
            ]);
        });
    }

    /**
     * Finance pays approved commission on $paidOn from the chosen paying account: PAID. Approved negligence of the employee is
     * recovered from the commission first (oldest first, carried forward when the commission is not enough). All-or-nothing.
     *
     * @param  Collection<int, CommissionAllocation>  $allocations
     *
     * @throws ValidationException
     * @throws AccessDeniedHttpException
     */
    public function pay(Collection $allocations, Employee $finance, CarbonImmutable $paidOn, string $source): int
    {
        $this->assertSegregation($allocations, $finance, 'pay');

        if ($paidOn->greaterThan(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['paid_on' => 'The payment date cannot be in the future.']);
        }

        return $this->transition($allocations, [CommissionAllocation::STATUS_FINANCE_APPROVED], 'paid', function (CommissionAllocation $row) use ($finance, $paidOn, $source): void {
            $this->payOne($row, $finance, $paidOn, $source);
        });
    }

    /**
     * API row of one employee commission: period, figures, expected or actual negligence deduction, net commission and the audit
     * trail of the payment flow.
     *
     * @param  array<int, float>  $outstandingNegligence  employee id → approved negligence still outstanding
     * @return array<string, mixed>
     */
    public function present(CommissionAllocation $row, array $outstandingNegligence = [], ?Employee $viewer = null, bool $canDecide = false): array
    {
        $amount = (float) $row->amount;
        $paid = $row->payment_status === CommissionAllocation::STATUS_PAID;
        $legacy = $row->payment_status === CommissionAllocation::STATUS_PAYROLL;
        $negligence = $paid ? (float) $row->negligence_deduction : ($legacy ? 0.0 : round(min($amount, $outstandingNegligence[$row->employee_id] ?? 0.0), 2));
        $percent = (float) $row->pool_percent;
        $base = $row->commission_base !== null ? (float) $row->commission_base : ($row->kind === CommissionAllocation::KIND_BRANCH_STAFF && $percent > 0 ? round((float) $row->pool_amount * 100 / $percent, 2) : null);
        $offset = $row->offset_amount !== null ? (float) $row->offset_amount : ($base !== null ? round(max(0.0, (float) $row->distributable_profit - $base), 2) : null);
        $initiators = [$row->requested_by, $row->employee_id];

        return [
            'id' => $row->id,
            'period' => $row->period?->period_start->format('Y-m'),
            'period_label' => $row->period?->period_start->format('F Y'),
            'closing_date' => ($row->period?->closed_at ?? $row->period?->period_end)?->toDateString(),
            'employee_id' => $row->employee_id,
            'employee' => $row->employee?->full_name,
            'branch_id' => $row->branch_id,
            'branch' => $row->branch?->name,
            'kind' => $row->kind,
            'distributable_profit' => (float) $row->distributable_profit,
            'offset_amount' => $offset,
            'commission_base' => $base,
            'pool_percent' => $percent,
            'pool_amount' => (float) $row->pool_amount,
            'zone_allocation' => $row->zone_allocation !== null ? (float) $row->zone_allocation : null,
            'share_percent' => (float) $row->share_percent,
            'calculated_amount' => $amount,
            'staff_commission' => $amount,
            'negligence_deduction' => $negligence,
            'negligence_expected' => ! $paid && ! $legacy,
            'net_commission' => $paid ? (float) $row->net_amount : round($amount - $negligence, 2),
            'status' => $row->payment_status,
            'status_label' => $legacy && $row->payrollRun?->status === 'paid' ? 'Paid via Payroll (legacy)' : $row->statusLabel(),
            'payroll_run_id' => $row->payroll_run_id,
            'finalized_by' => $row->finalizer?->full_name,
            'finalized_at' => $row->finalized_at?->toDateTimeString(),
            'requested_by' => $row->requester?->full_name,
            'requested_at' => $row->requested_at?->toDateTimeString(),
            'approved_by' => $row->approver?->full_name,
            'approved_at' => $row->approved_at?->toDateTimeString(),
            'rejected_by' => $row->rejecter?->full_name,
            'rejected_at' => $row->rejected_at?->toDateTimeString(),
            'rejection_reason' => $row->rejection_reason,
            'paid_by' => $row->payer?->full_name,
            'paid_at' => $row->paid_at?->toDateTimeString(),
            'paid_on' => $row->paid_on?->toDateString(),
            'paying_account' => $row->paying_account !== null ? Account::from($row->paying_account)->label() : null,
            'paying_branch' => $row->payingBranch?->name,
            'journal_reference' => $row->paymentJournalEntry?->reference,
            ...$this->duties->flags($initiators, $viewer, $row->payment_status === CommissionAllocation::STATUS_REQUESTED, $canDecide, workflow: ApprovalPolicy::PAYROLL),
            ...collect($this->duties->flags($initiators, $viewer, $row->payment_status === CommissionAllocation::STATUS_FINANCE_APPROVED, $canDecide, workflow: ApprovalPolicy::PAYROLL))
                ->mapWithKeys(fn ($value, string $key): array => [str_replace('approve', 'pay', $key) => $value])->all(),
        ];
    }

    /**
     * Approved negligence still outstanding per employee (for the expected deduction of unpaid commission).
     *
     * @param  list<int>  $employeeIds
     * @return array<int, float>
     */
    public function outstandingNegligence(array $employeeIds): array
    {
        return collect(array_unique($employeeIds))->mapWithKeys(fn (int $id): array => [$id => $this->negligence->outstandingFor($id)])->all();
    }

    /**
     * Totals and counts per status of a list of allocations.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function summary(array $rows): array
    {
        $rows = collect($rows);

        return [
            'total_commission' => round((float) $rows->sum('calculated_amount'), 2),
            'total_negligence' => round((float) $rows->sum('negligence_deduction'), 2),
            'total_net' => round((float) $rows->sum('net_commission'), 2),
            'total_paid' => round((float) $rows->where('status', CommissionAllocation::STATUS_PAID)->sum('net_commission'), 2),
            'counts' => collect(CommissionAllocation::STATUS_LABELS)->map(fn (string $label, string $status): array => ['label' => $label, 'count' => $rows->where('status', $status)->count()])->all(),
        ];
    }

    /**
     * Apply a status change to every allocation (re-read under a row lock) inside one transaction. Every allocation must be in one
     * of the expected statuses and not carried by a payroll run; each change is audited.
     *
     * @param  Collection<int, CommissionAllocation>  $allocations
     * @param  list<string>  $from
     * @param  callable(CommissionAllocation): void  $apply
     *
     * @throws ValidationException
     */
    private function transition(Collection $allocations, array $from, string $verb, callable $apply): int
    {
        if ($allocations->isEmpty()) {
            throw ValidationException::withMessages(['ids' => 'There is no commission to be '.$verb.'.']);
        }

        return DB::transaction(function () use ($allocations, $from, $verb, $apply): int {
            $locked = CommissionAllocation::whereIn('id', $allocations->pluck('id'))->with(['employee', 'period'])->orderBy('id')->lockForUpdate()->get();

            foreach ($locked as $row) {
                if ($row->payroll_run_id !== null || ! in_array($row->payment_status, $from, true)) {
                    throw ValidationException::withMessages(['ids' => "The {$row->period?->period_start->format('F Y')} commission of {$row->employee?->full_name} is {$row->statusLabel()} and cannot be {$verb}."]);
                }
            }

            foreach ($locked as $row) {
                $before = $row->payment_status;
                $apply($row);

                AuditLog::create([
                    'company_id' => $row->company_id,
                    'employee_id' => auth()->id(),
                    'action' => 'CommissionAllocation.'.str_replace(' ', '_', $verb),
                    'auditable_type' => $row->getMorphClass(),
                    'auditable_id' => $row->id,
                    'before' => ['payment_status' => $before],
                    'after' => collect($row->getChanges())->except(['updated_at'])->all(),
                    'ip_address' => request()?->ip(),
                ]);
            }

            return $locked->count();
        });
    }

    /**
     * @param  Collection<int, CommissionAllocation>  $allocations
     *
     * @throws AccessDeniedHttpException
     */
    private function assertSegregation(Collection $allocations, Employee $employee, string $action): void
    {
        foreach ($allocations as $row) {
            $reason = $this->duties->blockedReason([$row->requested_by, $row->employee_id], $employee, workflow: ApprovalPolicy::PAYROLL);
            if ($reason !== null) {
                throw new AccessDeniedHttpException($reason);
            }
        }
    }

    /**
     * Post the payment of one approved commission (the caller holds the row lock).
     *
     * @throws ValidationException
     */
    private function payOne(CommissionAllocation $row, Employee $finance, CarbonImmutable $paidOn, string $source): void
    {
        /** @var AccountingPeriod $period */
        $period = $row->period;
        $label = $period->period_start->format('F Y');

        if ($paidOn->lessThanOrEqualTo(CarbonImmutable::parse($period->period_end->toDateString()))) {
            throw ValidationException::withMessages(['paid_on' => "{$label} commission can only be paid after its period closed."]);
        }

        $gross = round((float) $row->amount, 2);
        $paying = $this->payingAccount($row, $source);
        $available = $this->ledger->balance($row->company_id, $paying['account'], $paying['branch']);
        if ($available + 0.001 < $gross) {
            throw ValidationException::withMessages(['ac_id' => 'Insufficient balance in '.$paying['account']->label().' ('.number_format($available, 2).' available) to pay '.$label.' commission of '.$row->employee?->full_name.'.']);
        }

        $lines = [];
        $left = $gross;
        foreach ($this->payableCredits($period, (int) $row->employee_id) as $branchId => $credited) {
            $portion = round(min($credited, $left), 2);
            if ($portion <= 0) {
                continue;
            }
            $lines[] = ['account' => Account::CommissionPayable, 'branch' => $branchId, 'employee' => $row->employee_id, 'debit' => $portion];
            $left = round($left - $portion, 2);
        }
        $lines[] = ['account' => Account::CommissionExpense, 'branch' => $row->branch_id, 'debit' => $left];

        $recoveries = $this->negligence->recover((int) $row->employee_id, $this->negligence->outstandingFor((int) $row->employee_id), $gross, $period->period_start, null, $row);
        $recovered = round((float) $recoveries->sum('amount'), 2);

        $lines[] = ['account' => Account::WriteOffExpense, 'branch' => $row->branch_id, 'credit' => $recovered];
        $lines[] = ['account' => Account::Principal, 'debit' => $recovered];
        $lines[] = ['account' => $paying['account'], 'branch' => $paying['branch'], 'credit' => $gross];

        $entry = $this->ledger->journal(
            $row->company_id,
            'COMMISSION PAYMENT '.$period->period_start->format('Y-m').' - '.$row->employee?->full_name,
            $lines,
            $row,
            $paidOn,
            $paying['branch'],
            $finance,
            TransactionType::CommissionPayment,
        );

        $recoveries->each(fn ($recovery) => $recovery->update(['journal_entry_id' => $entry->id]));

        $row->update([
            'payment_status' => CommissionAllocation::STATUS_PAID,
            'negligence_deduction' => $recovered,
            'net_amount' => round($gross - $recovered, 2),
            'paid_by' => $finance->id,
            'paid_at' => now(),
            'paid_on' => $paidOn->toDateString(),
            'paying_account' => $paying['account']->value,
            'paying_branch_id' => $paying['branch'],
            'payment_journal_entry_id' => $entry->id,
        ]);
    }

    /**
     * Branch INTEREST A/C of the allocation (where branch profit money sits, as the payroll paid commission before), or the
     * COMPANY ACCOUNT — always the company account when the allocation has no branch.
     *
     * @return array{account: Account, branch: int|null}
     */
    private function payingAccount(CommissionAllocation $row, string $source): array
    {
        $branchId = $row->branch_id ?? $row->employee?->branch_id;

        return $source === self::PAYING_INTEREST && $branchId !== null
            ? ['account' => Account::Interest, 'branch' => (int) $branchId]
            : ['account' => Account::Company, 'branch' => null];
    }

    /**
     * COMMISSION PAYABLE credited to the employee by the standing allocation journals of the period, per allocation branch.
     *
     * @return array<int, float> branch id → amount
     */
    private function payableCredits(AccountingPeriod $period, int $employeeId): array
    {
        $credits = [];
        foreach ($this->engine->allocationJournals($period) as $entry) {
            foreach ($entry->lines as $line) {
                if ($line->account?->key === Account::CommissionPayable && (int) $line->account->employee_id === $employeeId) {
                    $branchId = (int) $line->account->branch_id;
                    $credits[$branchId] = round(($credits[$branchId] ?? 0) + (float) $line->credit - (float) $line->debit, 2);
                }
            }
        }

        return $credits;
    }
}

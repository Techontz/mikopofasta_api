<?php

namespace App\Services\Customers;

use App\Enums\LoanStatus;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\Penalty;
use App\Models\SalaryAdvance;
use App\Services\Reports\LoanBalances;

/**
 * Everything a customer owes, as three separate debts that are never folded into one another:
 *
 *  - loan: outstanding principal (plus the interest and insurance of this system's loans) of every running loan;
 *  - penalty: every unpaid, unwaived penalty — on a running loan, or standing on its own (carried over from the old
 *    system with no loan behind it);
 *  - salary advance: what is left on every active salary advance.
 *
 * Loans, penalties and salary advances carried over from the old system (legacy import) count exactly like the rest, and
 * are also totalled apart under `old_system`, so a customer is never shown debt-free because the debt came from there.
 */
final class CustomerDebt
{
    /**
     * @return array{principal: float, interest: float, loan_total: float, penalty: float, penalty_without_loan: float, salary_advance: float, total: float, has_old_system_debt: bool, old_system: array{principal: float, penalty: float, salary_advance: float, total: float}, loans: list<array<string, mixed>>, salary_advances: list<array<string, mixed>>}
     */
    public function summary(Customer $customer): array
    {
        $loans = LoanBalances::join(Loan::query()->where('loans.customer_id', $customer->id)->whereIn('loans.status', LoanStatus::values(...LoanStatus::repayable())))
            ->orderBy('loans.id')
            ->get();

        $penalties = Penalty::query()
            ->where('customer_id', $customer->id)
            ->where('is_waived', false)
            ->whereColumn('paid_amount', '<', 'amount')
            ->where(fn ($query) => $query->whereNull('loan_id')->orWhereIn('loan_id', $loans->modelKeys() ?: [0]))
            ->get(['id', 'loan_id', 'amount', 'paid_amount', 'is_legacy_opening']);

        $advances = SalaryAdvance::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'active')
            ->whereNull('reversed_at')
            ->withSum('payments', 'amount')
            ->orderBy('id')
            ->get();

        $unpaid = fn (Penalty $penalty): float => (float) $penalty->amount - (float) $penalty->paid_amount;
        $principal = round((float) $loans->sum('out_principal'), 2);
        $interest = round((float) $loans->sum(fn (Loan $loan): float => (float) $loan->out_interest + (float) $loan->out_insurance), 2);
        $penalty = round((float) $penalties->sum($unpaid), 2);
        $salaryAdvance = round((float) $advances->sum(fn (SalaryAdvance $advance): float => $advance->remaining_amount), 2);

        $oldPrincipal = round((float) $loans->where('is_legacy_opening', true)->sum('out_principal'), 2);
        $oldPenalty = round((float) $penalties->where('is_legacy_opening', true)->sum($unpaid), 2);
        $oldAdvance = round((float) $advances->where('is_legacy_opening', true)->sum(fn (SalaryAdvance $advance): float => $advance->remaining_amount), 2);

        return [
            'principal' => $principal,
            'interest' => $interest,
            'loan_total' => round($principal + $interest, 2),
            'penalty' => $penalty,
            'penalty_without_loan' => round((float) $penalties->whereNull('loan_id')->sum($unpaid), 2),
            'salary_advance' => $salaryAdvance,
            'total' => round($principal + $interest + $penalty + $salaryAdvance, 2),
            'has_old_system_debt' => $oldPrincipal + $oldPenalty + $oldAdvance > 0.004,
            'old_system' => [
                'principal' => $oldPrincipal,
                'penalty' => $oldPenalty,
                'salary_advance' => $oldAdvance,
                'total' => round($oldPrincipal + $oldPenalty + $oldAdvance, 2),
            ],
            'loans' => $loans->map(fn (Loan $loan): array => [
                'id' => $loan->id,
                'loan_number' => $loan->loan_number,
                'reference_number' => $loan->reference_number,
                'status' => $loan->status->label(),
                'is_legacy_opening' => $loan->is_legacy_opening,
                'principal' => (float) $loan->out_principal,
                'interest' => round((float) $loan->out_interest + (float) $loan->out_insurance, 2),
                'penalty' => (float) $loan->out_penalty,
                'total' => (float) $loan->out_total,
            ])->values()->all(),
            'salary_advances' => $advances->map(fn (SalaryAdvance $advance): array => [
                'id' => $advance->id,
                'is_legacy_opening' => $advance->is_legacy_opening,
                'amount' => (float) $advance->amount,
                'total_payable' => (float) $advance->total_payable,
                'paid' => $advance->paid_amount,
                'remaining' => $advance->remaining_amount,
            ])->values()->all(),
        ];
    }
}

<?php

namespace App\Services;

use App\Enums\LoanStatus;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanOffset;
use App\Models\LoanTransaction;
use App\Models\Penalty;
use App\Models\PenaltyPayment;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvancePayment;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Debt clearance on a new loan: every existing debt of the customer is deducted from the loan's proceeds and settled when
 * the loan is paid out, through the existing records only.
 *
 * Debts: the customer's other loans still being repaid (their whole outstanding, penalties on them included), active
 * Salary Advances (their remaining balance), and penalties not tied to a loan. Old-system debts are these same records.
 *
 * - The breakdown is shown during approval ({@see self::plan()}); nothing is settled then.
 * - The batch keeps the plan it netted. Right before money moves it is recalculated from the latest confirmed balances
 *   ({@see self::revalidate()}), so a payment made after approval is not deducted again.
 * - On successful disbursement each debt is settled inside the disbursement transaction: loans by a TOPUP repayment,
 *   Salary Advances by a Salary Advance repayment, penalties by a penalty payment, each never more than what is still
 *   owed. A failed disbursement settles nothing.
 * - When the debts reach the loan proceeds the payout is stopped: no rule exists for a shortfall.
 */
class LoanDebtClearance
{
    public function __construct(
        private LoanService $loans,
        private SalaryAdvanceService $advances,
    ) {}

    /**
     * @return array{loans: list<array{id: int, loan_number: string, legacy: bool, amount: float, penalty: float}>, salary_advances: list<array{id: int, reference: string, legacy: bool, amount: float}>, penalties: list<array{id: int, date: string|null, legacy: bool, amount: float}>, totals: array{previous_loans: float, salary_advance: float, penalties: float, other: float, total_debts: float}, approved: float, loan_fee: float, net: float, exceeds: bool}
     */
    public function plan(Loan $loan): array
    {
        $customerId = (int) $loan->customer_id;

        $loans = Loan::query()->where('customer_id', $customerId)->whereKeyNot($loan->id)
            ->whereIn('status', LoanStatus::values(...LoanStatus::repayable()))->orderBy('id')->get()
            ->map(function (Loan $other): array {
                $outstanding = $this->loans->outstanding($other);

                return ['id' => $other->id, 'loan_number' => (string) $other->loan_number, 'legacy' => (bool) $other->is_legacy_opening,
                    'amount' => round((float) $outstanding['total'], 2), 'penalty' => round((float) $outstanding['penalty'], 2)];
            })
            ->filter(fn (array $row): bool => $row['amount'] > 0.004)->values()->all();

        $advances = SalaryAdvance::query()->where('customer_id', $customerId)->where('status', 'active')->whereNull('reversed_at')
            ->withSum('payments', 'amount')->orderBy('id')->get()
            ->map(fn (SalaryAdvance $advance): array => ['id' => $advance->id, 'reference' => 'SA-'.$advance->id,
                'legacy' => (bool) $advance->is_legacy_opening, 'amount' => round((float) $advance->remaining_amount, 2)])
            ->filter(fn (array $row): bool => $row['amount'] > 0.004)->values()->all();

        $penalties = Penalty::query()->where('customer_id', $customerId)->whereNull('loan_id')->where('is_waived', false)
            ->whereColumn('paid_amount', '<', 'amount')->orderBy('penalty_date')->orderBy('id')->get()
            ->map(fn (Penalty $penalty): array => ['id' => $penalty->id, 'date' => $penalty->penalty_date?->toDateString(), 'legacy' => (bool) $penalty->is_legacy_opening,
                'amount' => round((float) $penalty->amount - (float) $penalty->paid_amount, 2)])
            ->filter(fn (array $row): bool => $row['amount'] > 0.004)->values()->all();

        $loanTotal = array_sum(array_column($loans, 'amount'));
        $loanPenalties = array_sum(array_column($loans, 'penalty'));
        $totals = [
            'previous_loans' => round($loanTotal - $loanPenalties, 2),
            'salary_advance' => round(array_sum(array_column($advances, 'amount')), 2),
            'penalties' => round($loanPenalties + array_sum(array_column($penalties, 'amount')), 2),
            'other' => 0.0,
        ];
        $totals['total_debts'] = round($totals['previous_loans'] + $totals['salary_advance'] + $totals['penalties'] + $totals['other'], 2);

        $approved = round((float) ($loan->amount_approved > 0 ? $loan->amount_approved : $loan->amount_applied), 2);
        $fee = $loan->fee_deduct ? round((float) $loan->loan_fee, 2) : 0.0;
        $net = round($approved - $fee - $totals['total_debts'], 2);

        return [
            'loans' => $loans,
            'salary_advances' => $advances,
            'penalties' => $penalties,
            'totals' => $totals,
            'approved' => $approved,
            'loan_fee' => $fee,
            'net' => $net,
            'exceeds' => $totals['total_debts'] > 0.004 && $net <= 0,
        ];
    }

    /**
     * The payout is stopped when the existing debts reach the proceeds.
     *
     * @param  array{totals: array{total_debts: float}, approved: float, loan_fee: float, net: float, exceeds: bool}  $plan
     */
    public function assertPayable(array $plan): void
    {
        if ($plan['exceeds']) {
            throw ValidationException::withMessages(['loan' => 'The customer\'s existing debts ('.money($plan['totals']['total_debts']).') reach the loan proceeds ('
                .money($plan['approved'] - $plan['loan_fee']).'). The disbursement is stopped: settle part of the debts first.']);
        }
    }

    /**
     * Right before money moves: recalculate the debts from the latest confirmed balances and update a batch whose figure
     * changed. Returns true when it changed.
     */
    public function revalidate(LoanDisbursement $batch, Loan $loan): bool
    {
        $plan = $this->plan($loan);
        $this->assertPayable($plan);
        $previous = $batch->debt_settlement['plan'] ?? null;
        $net = round(max(0, $plan['net']), 2);

        if ($previous !== null && abs((float) $batch->amount - $net) <= 0.004 && $this->sameItems($previous, $plan)) {
            return false;
        }

        $batch->forceFill(['amount' => $net, 'debt_settlement' => ['plan' => $plan, 'revalidated_at' => now()->toIso8601String()]])->save();

        return true;
    }

    /**
     * Inside the successful disbursement transaction: settle what the batch netted, each debt capped at what is still owed
     * now. Batches prepared before debt clearance existed settle only the topped-up loan, as they always did.
     *
     * @return array{loans: list<array{loan_id: int, transaction_id: int, amount: float}>, salary_advances: list<array{id: int, payment_id: int, amount: float}>, penalties: list<array{id: int, payment_id: int, amount: float}>, unsettled: float}
     */
    public function settle(LoanDisbursement $batch, Loan $loan, CarbonImmutable $date, ?Employee $employee): array
    {
        $plan = $batch->debt_settlement['plan'] ?? $this->legacyPlan($loan);
        $result = ['loans' => [], 'salary_advances' => [], 'penalties' => [], 'unsettled' => 0.0];
        $transactions = [];

        foreach ($plan['loans'] as $item) {
            $other = Loan::whereKey($item['id'])->lockForUpdate()->first();
            $owed = $other !== null && in_array($other->status, LoanStatus::repayable(), true) ? round((float) $this->loans->outstanding($other)['total'], 2) : 0.0;
            $amount = round(min((float) $item['amount'], $owed), 2);
            $result['unsettled'] += (float) $item['amount'] - $amount;
            if ($amount > 0.004) {
                $transaction = $this->loans->deposit($other, $amount, $date, 'TOPUP', $employee);
                $transactions[] = $transaction;
                $result['loans'][] = ['loan_id' => $other->id, 'transaction_id' => $transaction->id, 'amount' => $amount];
            }
        }

        foreach ($plan['salary_advances'] as $item) {
            $advance = SalaryAdvance::whereKey($item['id'])->withSum('payments', 'amount')->first();
            $owed = $advance !== null && $advance->status === 'active' ? round((float) $advance->remaining_amount, 2) : 0.0;
            $amount = round(min((float) $item['amount'], $owed), 2);
            $result['unsettled'] += (float) $item['amount'] - $amount;
            if ($amount > 0.004) {
                $payment = $this->advances->pay($advance, $amount, $date, $employee);
                $payment->forceFill(['settled_by_loan_id' => $loan->id])->save();
                $result['salary_advances'][] = ['id' => $advance->id, 'payment_id' => $payment->id, 'amount' => $amount];
            }
        }

        foreach ($plan['penalties'] as $item) {
            $penalty = Penalty::whereKey($item['id'])->first();
            $owed = $penalty !== null && ! $penalty->is_waived ? round((float) $penalty->amount - (float) $penalty->paid_amount, 2) : 0.0;
            $amount = round(min((float) $item['amount'], $owed), 2);
            $result['unsettled'] += (float) $item['amount'] - $amount;
            if ($amount > 0.004) {
                $payment = $this->loans->payPenalty($penalty, $amount, $date, $employee);
                $payment->forceFill(['settled_by_loan_id' => $loan->id])->save();
                $result['penalties'][] = ['id' => $penalty->id, 'payment_id' => $payment->id, 'amount' => $amount];
            }
        }

        $result['unsettled'] = round(max(0, $result['unsettled']), 2);
        $this->recordOffset($loan, $batch, $transactions, $result, $date);
        $batch->forceFill(['debt_settlement' => ['plan' => $plan, 'settled' => $result] + ($batch->debt_settlement ?? [])])->save();

        return $result;
    }

    /**
     * What was netted that is not a loan repayment: the Salary Advance and standalone penalty payments of the batch.
     *
     * @return array{salary_advance_payments: list<SalaryAdvancePayment>, penalty_payments: list<PenaltyPayment>}
     */
    public function nonLoanSettlements(Loan $loan): array
    {
        return [
            'salary_advance_payments' => SalaryAdvancePayment::where('settled_by_loan_id', $loan->id)->whereNull('reversed_at')->orderByDesc('id')->get()->all(),
            'penalty_payments' => PenaltyPayment::where('settled_by_loan_id', $loan->id)->whereNull('reversed_at')->orderByDesc('id')->get()->all(),
        ];
    }

    /**
     * The non-cash part of the payout (§15): every debt cleared out of the proceeds, split by component, beside the cash
     * actually paid. One row per new loan; old_loan_id is the topped-up (or first settled) loan, if any.
     *
     * @param  list<LoanTransaction>  $transactions
     * @param  array{salary_advances: list<array{amount: float}>, penalties: list<array{amount: float}>}  $result
     */
    private function recordOffset(Loan $loan, LoanDisbursement $batch, array $transactions, array $result, CarbonImmutable $date): void
    {
        $advances = round(array_sum(array_column($result['salary_advances'], 'amount')), 2);
        $penalties = round(array_sum(array_column($result['penalties'], 'amount')), 2);
        if ($transactions === [] && $advances <= 0.004 && $penalties <= 0.004) {
            return;
        }

        $main = collect($transactions)->firstWhere('loan_id', $loan->topup_of_loan_id) ?? ($transactions[0] ?? null);
        $sum = fn (string $field): float => round(collect($transactions)->sum(fn ($transaction): float => (float) $transaction->{$field}), 2);

        LoanOffset::updateOrCreate(['new_loan_id' => $loan->id], [
            'company_id' => $loan->company_id,
            'branch_id' => $loan->branch_id,
            'customer_id' => $loan->customer_id,
            'old_loan_id' => $main?->loan_id,
            'loan_transaction_id' => $main?->id,
            'amount' => round($sum('amount') + $advances + $penalties, 2),
            'principal_amount' => $sum('principal'),
            'penalty_amount' => round($sum('penalty') + $penalties, 2),
            'interest_amount' => $sum('interest'),
            'salary_advance_amount' => $advances,
            'insurance_amount' => $sum('insurance'),
            'cash_disbursed' => round((float) $batch->amount, 2),
            'settled_on' => $date->toDateString(),
        ]);
    }

    /**
     * A batch prepared before debt clearance existed netted only the topped-up loan's balance.
     *
     * @return array{loans: list<array{id: int, amount: float}>, salary_advances: list<mixed>, penalties: list<mixed>}
     */
    private function legacyPlan(Loan $loan): array
    {
        $previous = $loan->topupOf;
        $loans = $previous !== null && in_array($previous->status, LoanStatus::repayable(), true)
            ? [['id' => $previous->id, 'amount' => round((float) $this->loans->outstanding($previous)['total'], 2)]]
            : [];

        return ['loans' => $loans, 'salary_advances' => [], 'penalties' => []];
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $plan
     */
    private function sameItems(array $previous, array $plan): bool
    {
        $key = fn (array $rows): array => array_map(fn (array $row): string => $row['id'].':'.number_format((float) $row['amount'], 2, '.', ''), $rows);

        return $key($previous['loans'] ?? []) === $key($plan['loans'])
            && $key($previous['salary_advances'] ?? []) === $key($plan['salary_advances'])
            && $key($previous['penalties'] ?? []) === $key($plan['penalties']);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Loans\Services;

use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Builder;

/**
 * The three charge registers — Penalty → Penalty List, Penalty → Paid Penalty,
 * and Loan Fee → Deducted Income.
 *
 * All three answer the same shape of question about a different charge, and all
 * three are **reads over records other modules already write**. Nothing here
 * stores a penalty or a fee: a penalty lives on `loan_schedules.penalty_due`
 * where the overdue job put it, a paid one on `payment_allocations` where the
 * repayment engine put it, and a fee on `loans.fee_charged` where disbursement
 * put it.
 *
 * That is the whole design decision. A `penalties` table would be a fourth copy
 * of figures the schedule, the allocation and the ledger already hold, and the
 * first time it disagreed with any of them there would be no way to say which
 * was right. Reading through means these screens cannot drift from the loan
 * book — they are the loan book, filtered.
 *
 * Each method returns a Builder rather than results, so the controller owns
 * pagination and the caller can count without fetching.
 */
final class ChargeLedgerQueries
{
    /**
     * Penalties charged and not yet fully paid — Penalty → Penalty List.
     *
     * One row per installment carrying a penalty, which is the grain the
     * legacy screen shows: a loan with three overdue installments appears three
     * times, once per charge, rather than once with a total nobody can trace.
     *
     * @return Builder<LoanSchedule>
     */
    public function accruedPenalties(): Builder
    {
        return LoanSchedule::query()
            ->with(['loan.customer', 'loan.branch'])
            ->whereHas('loan')
            // `penalty_due` is what the overdue job accrued; a schedule that
            // never went overdue holds zero and is not a penalty at all.
            ->where('penalty_due', '>', 0);
    }

    /**
     * Penalty money actually collected — Penalty → Paid Penalty.
     *
     * Read from `payment_allocations`, not from `loan_schedules.penalty_paid`.
     * The schedule column is a running total and cannot say *when* any of it
     * arrived; the allocation rows can, and the legacy screen has a date
     * column. Allocations are also what the ledger posted against, so this list
     * and 2200 Penalty Income are the same events counted the same way.
     *
     * @return Builder<PaymentAllocation>
     */
    public function paidPenalties(): Builder
    {
        return PaymentAllocation::query()
            ->with(['payment', 'schedule.loan.customer', 'schedule.loan.branch'])
            ->whereHas('schedule.loan')
            ->where('penalty_allocated', '>', 0);
    }

    /**
     * Fee income withheld at disbursement — Loan Fee → Deducted Income.
     *
     * `fee_charged` is null on a loan that has not disbursed and zero on one
     * whose product charges nothing; both are excluded, because neither is
     * income and the legacy screen lists income.
     *
     * @return Builder<Loan>
     */
    public function deductedIncome(): Builder
    {
        return Loan::query()
            ->with(['customer', 'branch'])
            ->where('fee_charged', '>', 0);
    }

    /**
     * Narrows the deducted-income register, whose rows are loans.
     *
     * The matching itself is `Loan::scopeSearch` — it already spans the loan
     * number, the customer number, the phone and the customer's assembled full
     * name, which is the awkward part, and a second definition here would be
     * one more place to get it wrong.
     *
     * @param Builder<Loan> $query
     * @return Builder<Loan>
     */
    public function searchLoans(Builder $query, string $term): Builder
    {
        $term = trim($term);

        return $term === '' ? $query : $query->search($term);
    }

    /**
     * The same search, for a register whose rows only reach a loan through a
     * relation — a schedule through one hop, an allocation through two.
     *
     * Separate from `searchLoans` rather than one method taking an optional
     * path: the two operate on genuinely different builders, and collapsing
     * them meant a sentinel empty string that hid the difference from both the
     * reader and the type checker.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param Builder<TModel> $query
     * @param string $loanPath relation path from the row to the loan
     * @return Builder<TModel>
     */
    public function searchThroughLoan(Builder $query, string $term, string $loanPath): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->whereHas($loanPath, static function (Builder $query) use ($term): void {
            /**
             * Narrowed here rather than in the signature: `whereHas` hands the
             * closure a builder typed only as Model, and the relation path is
             * a runtime string, so nothing upstream can name Loan for it.
             *
             * @var Builder<Loan> $query
             */
            $query->search($term);
        });
    }
}

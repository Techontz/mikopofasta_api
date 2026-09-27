<?php

namespace App\Services\Reports;

use App\Services\LoanService;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Set-based equivalent of {@see LoanService::outstanding()} for report queries.
 *
 * Repayments are split at posting time in the order Principal → Penalty → Interest → Insurance and stored on
 * `loan_transactions`, so per loan: outstanding principal = approved − Σ principal paid, interest = interest
 * amount − Σ interest paid, insurance = insurance − Σ insurance paid, penalty = Σ (amount − paid) of unwaived
 * penalties (each floored at 0). Reversed repayments (`reversed_at` set) are ignored. A loan carried over from the old
 * system also subtracts `opening_paid_principal`, what that system had already collected, so it opens at its printed
 * Remain Amount without a repayment of this system standing behind it. Adds the columns `out_principal`, `out_interest`, `out_insurance`,
 * `out_penalty`, `out_total`, `paid_total`, `paid_principal`, `paid_interest`, `paid_penalty` to a `loans` query.
 */
final class LoanBalances
{
    /**
     * @template TBuilder of BuilderContract
     *
     * @param  TBuilder  $loans  a query on the `loans` table
     * @return TBuilder
     */
    public static function join(BuilderContract $loans): BuilderContract
    {
        $paid = DB::table('loan_transactions')
            ->where('type', 'deposit')
            ->whereNull('reversed_at')
            ->whereNotNull('loan_id')
            ->groupBy('loan_id')
            ->selectRaw('loan_id, SUM(amount) amount, SUM(principal) principal, SUM(interest) interest, SUM(insurance) insurance, SUM(penalty) penalty');

        $penalties = DB::table('penalties')
            ->where('is_waived', false)
            ->whereNotNull('loan_id')
            ->groupBy('loan_id')
            ->selectRaw('loan_id, SUM(amount - paid_amount) unpaid');

        $principal = 'GREATEST(0, loans.amount_approved - loans.opening_paid_principal - COALESCE(lb_paid.principal, 0))';
        $interest = 'GREATEST(0, loans.interest_amount - COALESCE(lb_paid.interest, 0))';
        $insurance = 'GREATEST(0, loans.insurance - COALESCE(lb_paid.insurance, 0))';
        $penalty = 'GREATEST(0, COALESCE(lb_pen.unpaid, 0))';

        $base = $loans instanceof EloquentBuilder ? $loans->getQuery() : $loans;
        if ($base->columns === null) {
            $loans->select('loans.*');
        }

        return $loans
            ->leftJoinSub($paid, 'lb_paid', 'lb_paid.loan_id', '=', 'loans.id')
            ->leftJoinSub($penalties, 'lb_pen', 'lb_pen.loan_id', '=', 'loans.id')
            ->addSelect([
                DB::raw("ROUND({$principal}, 2) as out_principal"),
                DB::raw("ROUND({$interest}, 2) as out_interest"),
                DB::raw("ROUND({$insurance}, 2) as out_insurance"),
                DB::raw("ROUND({$penalty}, 2) as out_penalty"),
                DB::raw("ROUND({$principal} + {$interest} + {$insurance} + {$penalty}, 2) as out_total"),
                DB::raw('COALESCE(lb_paid.amount, 0) as paid_total'),
                DB::raw('COALESCE(lb_paid.principal, 0) as paid_principal'),
                DB::raw('COALESCE(lb_paid.interest, 0) as paid_interest'),
                DB::raw('COALESCE(lb_paid.penalty, 0) as paid_penalty'),
            ]);
    }
}

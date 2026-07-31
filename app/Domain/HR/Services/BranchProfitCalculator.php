<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use App\Domain\Ledger\Enums\AccountType;
use App\Models\Branch;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A branch's profit for a period, read off the ledger.
 *
 * §8 defines it once: "Profit = Income accounts − Expense accounts (Reserve
 * already netted out) per branch". §12 adds that every branch-scoped report is
 * "a simple filtered query" over `journal_entry_lines.branch_id`, which is
 * exactly what this is.
 *
 * This is the input to the commission engine, and computing it from the ledger
 * rather than accepting it as a parameter is what makes commission traceable:
 * a manager asking why the pool is what it is can be shown the entries behind
 * it. The alternative — a figure typed in by Finance — would make commission
 * an assertion rather than a consequence.
 *
 * ## On the Reserve
 *
 * §8's parenthetical "(Reserve already netted out)" is satisfied by how the
 * reserve cut is posted, not by anything here: §5 takes it as Dr Interest
 * Income · Cr Reserve on the same entry as the collection, so the income
 * account already carries interest net of reserve by the time it is summed.
 * Subtracting the reserve again here would remove it twice.
 */
final class BranchProfitCalculator
{
    /**
     * Income minus expense for one branch over one `YYYY-MM` period.
     *
     * Signed: a branch whose expenses exceed its income returns a negative
     * figure, which is precisely what §11's loss rules act on.
     */
    public function forPeriod(Branch $branch, string $period): Money
    {
        [$start, $end] = $this->periodBounds($period);

        $totals = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('jel.branch_id', $branch->getKey())
            ->whereIn('coa.type', [AccountType::Income->value, AccountType::Expense->value])
            ->whereBetween('je.entry_date', [$start->toDateString(), $end->toDateString()])
            ->select('coa.type')
            ->selectRaw('SUM(jel.debit_amount) AS debit_total, SUM(jel.credit_amount) AS credit_total')
            ->groupBy('coa.type')
            ->get()
            ->keyBy('type');

        $income = $totals->get(AccountType::Income->value);
        $expense = $totals->get(AccountType::Expense->value);

        // Income is credit-normal and expense debit-normal, so each is netted
        // on its own side — a refunded fee (a debit to income) genuinely
        // reduces income rather than looking like an expense.
        $incomeNet = Money::of((string) ($income->credit_total ?? '0.00'))
            ->subtract(Money::of((string) ($income->debit_total ?? '0.00')));

        $expenseNet = Money::of((string) ($expense->debit_total ?? '0.00'))
            ->subtract(Money::of((string) ($expense->credit_total ?? '0.00')));

        return $incomeNet->subtract($expenseNet);
    }

    /**
     * The first and last day of a `YYYY-MM` period.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function periodBounds(string $period): array
    {
        // Validated by shape first: Carbon will happily read "2026-13" as
        // January 2027, which would silently compute the wrong month's profit.
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new InvalidArgumentException("[{$period}] is not a valid YYYY-MM period.");
        }

        $start = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01');

        return [$start->startOfMonth(), $start->endOfMonth()];
    }

    /** The period immediately before `$period`, as `YYYY-MM`. */
    public function previousPeriod(string $period): string
    {
        [$start] = $this->periodBounds($period);

        return $start->subMonth()->format('Y-m');
    }
}

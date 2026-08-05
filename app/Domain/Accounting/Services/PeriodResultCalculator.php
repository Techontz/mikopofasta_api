<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\DTOs\AccountMovement;
use App\Domain\Accounting\DTOs\PeriodResult;
use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * What a period earned — the figures the close acts on, and the P&L reports read.
 *
 * One class rather than a query repeated in nine reports, because "realised
 * profit for August" has to mean exactly one thing. The commission engine, the
 * close, and every branch P&L must agree to the cent, or a manager can be shown
 * two different answers to the same question on two different screens.
 *
 * Two rules define it:
 *
 *   1. **Period-scoped, not cumulative.** Income and expense are movements
 *      within the month, never balances carried since inception.
 *   2. **Trading entries only.** The close itself posts to income and expense
 *      accounts when it sweeps them into Profit; counting those would net every
 *      closed period to zero.
 */
final class PeriodResultCalculator
{
    /**
     * Every branch's result for one period, plus the unbranched remainder.
     *
     * One query for all branches rather than one per branch: a close touching
     * forty branches should not be forty round trips, and the commission engine
     * asking about one branch is answered from the same rows.
     */
    public function forPeriod(string $period): PeriodResult
    {
        [$start, $end] = self::bounds($period);

        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->whereIn('coa.type', [AccountType::Income->value, AccountType::Expense->value])
            ->whereBetween('je.entry_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotIn('je.source_type', JournalSourceType::periodClosingValues())
            ->select('jel.account_id', 'jel.branch_id', 'coa.type')
            ->selectRaw('SUM(jel.debit_amount) AS debit_total, SUM(jel.credit_amount) AS credit_total')
            ->groupBy('jel.account_id', 'jel.branch_id', 'coa.type')
            ->get();

        $movements = [];

        foreach ($rows as $row) {
            $type = AccountType::from((string) $row->type);

            $debit = Money::of((string) $row->debit_total);
            $credit = Money::of((string) $row->credit_total);

            /*
             * Netted on the account's own normal side. A refunded fee is a
             * debit to an income account, and it genuinely reduces income —
             * treating it as an expense would understate both figures while
             * leaving profit correct, which is the worst of both.
             */
            $net = $type === AccountType::Income
                ? $credit->subtract($debit)
                : $debit->subtract($credit);

            if ($net->isZero()) {
                continue;
            }

            $movements[] = new AccountMovement(
                accountId: (int) $row->account_id,
                branchId: $row->branch_id === null ? null : (int) $row->branch_id,
                type: $type,
                net: $net,
            );
        }

        return new PeriodResult($period, $start, $end, $movements);
    }

    /**
     * The first and last day of a `YYYY-MM` period.
     *
     * Shape-checked before Carbon sees it: Carbon reads "2026-13" as January
     * 2027 without complaint, which would close the wrong month's books.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public static function bounds(string $period): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new InvalidArgumentException("[{$period}] is not a valid YYYY-MM period.");
        }

        $start = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01');

        return [$start->startOfMonth(), $start->endOfMonth()];
    }

    /** The period immediately before `$period`, as `YYYY-MM`. */
    public static function previous(string $period): string
    {
        [$start] = self::bounds($period);

        return $start->subMonth()->format('Y-m');
    }
}

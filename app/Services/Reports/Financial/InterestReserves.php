<?php

namespace App\Services\Reports\Financial;

use App\Enums\Account;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * The one definition of the interest reserve inside a set of journal lines (Fund Flow Specification §6, Rule 4; user
 * decision D6 "Reserve is NOT income"), shared by the month-end close and the Profit & Loss reports.
 *
 *  - LEGACY repayment entries credited the FULL interest to INTEREST INCOME and debited the reserve cut to the RESERVE A/C
 *    fund, with no INTEREST RESERVE line: that reserve fund debit is still inside interest income and must be subtracted.
 *  - NEW repayment entries credit INTEREST INCOME with the interest net of the reserve and INTEREST RESERVE (equity) with the
 *    reserve: interest income is already net, nothing is subtracted.
 *
 * Reversals mirror their originals line for line, so a reversed legacy entry subtracts a negative reserve and a reversed new
 * entry debits INTEREST RESERVE back — both net out automatically.
 */
final class InterestReserves
{
    /**
     * Reserve per branch (0 = HQ) for the lines selected by $base.
     *
     * @param  callable(): Builder  $base  a fresh journal_lines query joined on `accounts` and `journal_entries`, already filtered
     * @return array{legacy: array<int, float>, total: array<int, float>} legacy = reserve still inside interest income
     *                                                                    (to subtract); total = legacy + new INTEREST RESERVE credits
     */
    public static function byBranch(callable $base): array
    {
        $legacy = $base()
            ->where('accounts.key', Account::Reserve->value)
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('journal_lines AS income_lines')
                ->join('accounts AS income_accounts', 'income_accounts.id', '=', 'income_lines.account_id')
                ->whereColumn('income_lines.journal_entry_id', 'journal_lines.journal_entry_id')
                ->where('income_accounts.key', Account::InterestIncome->value))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('journal_lines AS reserve_lines')
                ->join('accounts AS reserve_accounts', 'reserve_accounts.id', '=', 'reserve_lines.account_id')
                ->whereColumn('reserve_lines.journal_entry_id', 'journal_lines.journal_entry_id')
                ->where('reserve_accounts.key', Account::InterestReserve->value))
            ->groupBy('accounts.branch_id')
            ->selectRaw('COALESCE(accounts.branch_id, 0) AS branch, SUM(journal_lines.debit) - SUM(journal_lines.credit) AS amount')
            ->pluck('amount', 'branch');

        $new = $base()
            ->where('accounts.key', Account::InterestReserve->value)
            ->groupBy('accounts.branch_id')
            ->selectRaw('COALESCE(accounts.branch_id, 0) AS branch, SUM(journal_lines.credit) - SUM(journal_lines.debit) AS amount')
            ->pluck('amount', 'branch');

        $legacyByBranch = [];
        foreach ($legacy as $branch => $amount) {
            $legacyByBranch[(int) $branch] = round((float) $amount, 2);
        }

        $total = $legacyByBranch;
        foreach ($new as $branch => $amount) {
            $total[(int) $branch] = round(($total[(int) $branch] ?? 0) + (float) $amount, 2);
        }

        return ['legacy' => $legacyByBranch, 'total' => $total];
    }
}

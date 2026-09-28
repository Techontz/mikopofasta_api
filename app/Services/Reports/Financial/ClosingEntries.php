<?php

namespace App\Services\Reports\Financial;

use App\Models\AccountingPeriod;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Month-end closing entries (journal source AccountingPeriod) move every income and expense balance into the Profit
 * Account. Reports of income, expenses or profit for a date range must ignore them, otherwise a closed month nets to ~0.
 */
final class ClosingEntries
{
    /**
     * Exclude closing entries from a query joined on `journal_entries`.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function exclude(Builder $query, string $table = 'journal_entries'): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->whereNull("{$table}.source_type")
            ->orWhere("{$table}.source_type", '!=', (new AccountingPeriod)->getMorphClass()));
    }
}

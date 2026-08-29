<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * The demonstration institution's bank accounts — development and test only.
 *
 * These two rows used to live inside `ChartOfAccountSeeder`, which
 * `ProductionSeeder` runs. That meant a fresh production installation arrived
 * holding accounts at two named banks under two invented account numbers,
 * belonging to a company it had never heard of.
 *
 * Which banks an institution keeps its float with is a Treasury decision,
 * stated at Treasury → Bank Accounts. The chart of accounts itself stays in
 * `ChartOfAccountSeeder` and is genuinely structural: the ledger posts to
 * accounts by code, and an empty chart means nothing can be recorded at all.
 *
 * Extending the parent rather than duplicating it, so the demo path and the
 * production path build the chart identically and differ only in this list.
 */
final class DemoBankAccountSeeder extends ChartOfAccountSeeder
{
    /**
     * @return list<array{bank_name: string, account_number: string, account_name: string, code: string}>
     */
    protected function bankAccounts(): array
    {
        return [
            ['bank_name' => 'CRDB Bank', 'account_number' => '0150312345600', 'account_name' => 'Mikopofasta Microfinance Limited', 'code' => '8000'],
            ['bank_name' => 'NMB Bank', 'account_number' => '2011098765400', 'account_name' => 'Mikopofasta Microfinance Limited', 'code' => '8010'],
        ];
    }
}

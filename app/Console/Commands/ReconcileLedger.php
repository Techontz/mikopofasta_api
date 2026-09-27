<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Accounting\LedgerIntegrity;
use Illuminate\Console\Command;

/**
 * Read-only ledger integrity and sub-ledger reconciliation report. Exits with a failure code when any check fails.
 */
class ReconcileLedger extends Command
{
    protected $signature = 'ledger:reconcile {--company= : Only this company id} {--json : Print the full report as JSON}';

    protected $description = 'Verify ledger integrity (balanced entries, reversals, closed periods) and reconcile sub-ledgers (read-only)';

    public function handle(LedgerIntegrity $integrity): int
    {
        $companyIds = $this->option('company') !== null
            ? [(int) $this->option('company')]
            : Company::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $reports = array_map(fn (int $companyId): array => $integrity->run($companyId), $companyIds);

        if ($this->option('json')) {
            $this->line((string) json_encode($reports, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($reports as $report) {
                $this->info("Company #{$report['company_id']} {$report['company']}: ".strtoupper($report['status']));
                $this->table(['Check', 'Status', 'Message'], array_map(
                    fn (array $check): array => [$check['title'], strtoupper($check['status']), $check['message']],
                    $report['checks'],
                ));
            }
        }

        return collect($reports)->contains(fn (array $report): bool => $report['status'] === LedgerIntegrity::FAIL) ? self::FAILURE : self::SUCCESS;
    }
}

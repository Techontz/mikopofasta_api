<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Company;
use App\Models\HistoricalPenaltyReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports a "PENARTY REPORT" printed by the old system (e.g. database/data/historical/kakonko-penalty-report.json) as
 * history: a historical_penalty_reports row and one historical_penalty_records row per printed row. Figures are stored
 * as printed — nothing is recalculated — and nothing is posted: no penalty, payment, receipt or journal entry is
 * created, and no customer's balance moves.
 *
 * The manifest names the report (code, title, branch, source document, print date, printed TOTAL) and its CSV of rows:
 * s_no, customer_name, loan_amount, penalty_amount, penalty_date. Safe to run again: the report is matched on its code
 * and its rows are replaced. A branch that does not exist yet is imported with no branch (visible company-wide only) so
 * no data is lost; the command says so, and re-running it after the branch is created attaches the report.
 */
class ImportHistoricalPenaltyReport extends Command
{
    protected $signature = 'mkopa:import-historical-penalty-report
        {manifest : Report manifest (JSON), relative to the API root}
        {--company= : Company id (defaults to the only company)}';

    protected $description = 'Import a historical Penalty report (records only — no penalties, cash or ledger entries)';

    public function handle(): int
    {
        try {
            $manifestPath = $this->resolvePath((string) $this->argument('manifest'));
            $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
            $rows = $this->readRows(dirname($manifestPath).DIRECTORY_SEPARATOR.$manifest['rows']);
            $company = $this->company();
        } catch (RuntimeException|\JsonException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $branch = Branch::query()->where('company_id', $company->id)->whereRaw('UPPER(name) = ?', [strtoupper($manifest['branch'])])->first();
        if ($branch === null) {
            $this->warn("Branch {$manifest['branch']} does not exist in {$company->name}; the report is imported without a branch and is visible to head-office users only. Create the branch and run this again to attach it.");
        }

        DB::transaction(function () use ($manifest, $rows, $company, $branch): void {
            $report = HistoricalPenaltyReport::query()->updateOrCreate(
                ['company_id' => $company->id, 'code' => $manifest['code']],
                [
                    'branch_id' => $branch?->id,
                    'title' => $manifest['title'],
                    'branch_name' => $manifest['branch'],
                    'source_document' => $manifest['source_document'],
                    'printed_on' => $manifest['printed_on'] ?? null,
                    'printed_total' => $manifest['printed_total'] ?? null,
                    'notes' => $manifest['notes'] ?? null,
                    'imported_at' => now(),
                ],
            );
            $report->records()->delete();

            foreach ($rows as $row) {
                $report->records()->create([
                    'serial_number' => (int) $row['s_no'],
                    'customer_name' => $row['customer_name'] !== '' ? $row['customer_name'] : null,
                    'branch_name' => $manifest['branch'],
                    'loan_amount' => $row['loan_amount'],
                    'penalty_amount' => $row['penalty_amount'],
                    'penalty_date' => $row['penalty_date'] !== '' ? $row['penalty_date'] : null,
                ]);
            }
        });

        $total = array_sum(array_map(fn (array $row): float => (float) $row['penalty_amount'], $rows));
        $printed = isset($manifest['printed_total']) ? (float) $manifest['printed_total'] : null;
        $this->info(sprintf('Imported %s: %d records, penalties totalling %s.', $manifest['title'], count($rows), number_format($total, 2)));
        if ($printed !== null && abs($printed - $total) >= 0.01) {
            $this->warn(sprintf('The printed TOTAL is %s, %s more than the rows add up to; it is kept as printed.', number_format($printed, 2), number_format($printed - $total, 2)));
        }
        $this->line('No penalties, payments, transactions or journal entries were created.');

        return self::SUCCESS;
    }

    private function company(): Company
    {
        if ($this->option('company') !== null) {
            return Company::query()->findOrFail((int) $this->option('company'));
        }

        $companies = Company::query()->limit(2)->get();
        if ($companies->count() !== 1) {
            throw new RuntimeException('More than one company exists; pass --company=<id>.');
        }

        return $companies->first();
    }

    private function resolvePath(string $path): string
    {
        $resolved = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        if (! is_file($resolved)) {
            throw new RuntimeException("File not found: {$resolved}");
        }

        return $resolved;
    }

    /**
     * Rows of the CSV, validated: every amount numeric, every date a real Y-m-d date, serial numbers unique.
     *
     * @return list<array<string, string>>
     */
    private function readRows(string $path): array
    {
        $handle = fopen($this->resolvePath($path), 'r');
        $header = fgetcsv($handle, escape: '');
        $rows = [];
        $serials = [];
        while (($values = fgetcsv($handle, escape: '')) !== false) {
            if ($values === [null]) {
                continue;
            }
            if (count($values) !== count($header)) {
                throw new RuntimeException(sprintf('Row %d has %d columns, expected %d.', count($rows) + 1, count($values), count($header)));
            }
            $row = array_map('trim', array_combine($header, $values));
            $line = "S/No. {$row['s_no']}";

            foreach (['s_no', 'loan_amount', 'penalty_amount'] as $column) {
                if (! is_numeric($row[$column])) {
                    throw new RuntimeException("{$line}: {$column} is not a number.");
                }
            }
            if ($row['penalty_date'] !== '' && CarbonImmutable::createFromFormat('!Y-m-d', $row['penalty_date'])?->format('Y-m-d') !== $row['penalty_date']) {
                throw new RuntimeException("{$line}: penalty_date is not a Y-m-d date.");
            }
            if (isset($serials[$row['s_no']])) {
                throw new RuntimeException("{$line} appears twice.");
            }
            $serials[$row['s_no']] = true;
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}

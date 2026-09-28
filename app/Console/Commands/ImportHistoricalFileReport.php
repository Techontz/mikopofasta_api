<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Company;
use App\Models\HistoricalFileReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports a File report printed by the old system (e.g. database/data/historical/kakonko-2022-file-report.json) as
 * history: a historical_file_reports row, one historical_file_records row per printed row and one
 * historical_file_payments row per non-zero month column. Figures are stored as printed — nothing is recalculated —
 * and nothing is posted: no loan, loan transaction, payment or journal entry is created.
 *
 * The manifest names the report (code, title, branch, year, source document, printed TOTAL row) and its CSV of rows:
 * s_no, customer_name, phone, loan_amount, duration_type, sessions, collection, paid_amount, remain_amount,
 * withdrawal_date, status, jan … dec. Safe to run again: the report is matched on its code and its rows are replaced.
 */
class ImportHistoricalFileReport extends Command
{
    private const MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

    protected $signature = 'mkopa:import-historical-file-report
        {manifest=database/data/historical/kakonko-2022-file-report.json : Report manifest (JSON), relative to the API root}
        {--company= : Company id (defaults to the only company)}';

    protected $description = 'Import a historical File report (records only — no cash, loans or ledger entries)';

    public function handle(): int
    {
        try {
            $manifestPath = $this->resolvePath((string) $this->argument('manifest'));
            $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
            $rows = $this->readRows(dirname($manifestPath).DIRECTORY_SEPARATOR.$manifest['rows']);
            $company = $this->company();
            $branch = Branch::query()->where('company_id', $company->id)->whereRaw('UPPER(name) = ?', [strtoupper($manifest['branch'])])->first()
                ?? throw new RuntimeException("Branch {$manifest['branch']} does not exist in {$company->name}.");
        } catch (RuntimeException|\JsonException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $year = (int) $manifest['year'];
        $payments = 0;

        DB::transaction(function () use ($manifest, $rows, $company, $branch, $year, &$payments): void {
            $report = HistoricalFileReport::query()->updateOrCreate(
                ['company_id' => $company->id, 'code' => $manifest['code']],
                [
                    'branch_id' => $branch->id,
                    'title' => $manifest['title'],
                    'branch_name' => $manifest['branch'],
                    'year' => $year,
                    'source_document' => $manifest['source_document'],
                    'printed_totals' => $manifest['printed_totals'] ?? null,
                    'notes' => $manifest['notes'] ?? null,
                    'imported_at' => now(),
                ],
            );
            $report->records()->delete();

            foreach ($rows as $row) {
                $record = $report->records()->create([
                    'serial_number' => (int) $row['s_no'],
                    'customer_name' => $row['customer_name'],
                    'phone' => $row['phone'] !== '' ? $row['phone'] : null,
                    'loan_amount' => $row['loan_amount'],
                    'duration_type' => $row['duration_type'] !== '' ? $row['duration_type'] : null,
                    'sessions' => $row['sessions'] !== '' ? (int) $row['sessions'] : null,
                    'collection' => $row['collection'],
                    'paid_amount' => $row['paid_amount'],
                    'remain_amount' => $row['remain_amount'],
                    'withdrawal_date' => $row['withdrawal_date'] !== '' ? $row['withdrawal_date'] : null,
                    'status' => $row['status'] !== '' ? $row['status'] : null,
                ]);

                foreach (self::MONTHS as $index => $month) {
                    if ((float) $row[$month] != 0.0) {
                        $record->payments()->create(['year' => $year, 'month' => $index + 1, 'amount' => $row[$month]]);
                        $payments++;
                    }
                }
            }
        });

        $this->info(sprintf('Imported %s: %d records, %d monthly payment figures (branch %s, %d).', $manifest['title'], count($rows), $payments, $branch->name, $year));
        $this->line('No loans, transactions or journal entries were created.');

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

            foreach (['s_no', 'loan_amount', 'collection', 'paid_amount', 'remain_amount', ...self::MONTHS] as $column) {
                if (! is_numeric($row[$column])) {
                    throw new RuntimeException("{$line}: {$column} is not a number.");
                }
            }
            if ($row['withdrawal_date'] !== '' && CarbonImmutable::createFromFormat('!Y-m-d', $row['withdrawal_date'])?->format('Y-m-d') !== $row['withdrawal_date']) {
                throw new RuntimeException("{$line}: withdrawal_date is not a Y-m-d date.");
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

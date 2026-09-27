<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\HistoricalPenaltyRecord;
use App\Models\HistoricalPenaltyReport;
use App\Services\Customers\HistoricalNameMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Links the rows of imported historical Penalty reports to the customers already in the system, so the Penalty report
 * can open the person behind a row. Nothing is created: a row whose name matches no customer, or more than one, is
 * left unlinked and reported.
 *
 * The two printouts spell the same person differently — a Penalty report prints the middle name in full
 * ("SHABAN DAUD MASONJO") where a File report prints its initial ("SHABAN D. MASONJO") — so names are compared with
 * {@see HistoricalNameMatcher}. Matching is company-wide, because one person may appear in more than one branch, but
 * a customer of the report's own branch wins: two people can share a name across branches (there is a FESTO E.
 * NYAGAWA at Makambako and another at Wanging'ombe), and a Makambako penalty belongs to the Makambako one.
 */
class LinkHistoricalPenalties extends Command
{
    protected $signature = 'mkopa:link-historical-penalties
        {--company= : Company id (defaults to every company)}
        {--report= : Only this report code}
        {--dry-run : Report the matches without writing}';

    protected $description = 'Link historical Penalty report rows to existing customers (creates nothing)';

    public function handle(): int
    {
        $reports = HistoricalPenaltyReport::query()
            ->when($this->option('company') !== null, fn ($query) => $query->where('company_id', (int) $this->option('company')))
            ->when($this->option('report') !== null, fn ($query) => $query->where('code', $this->option('report')))
            ->with(['records' => fn ($query) => $query->orderBy('serial_number')])
            ->get();

        if ($reports->isEmpty()) {
            $this->error('No historical Penalty report matches; run mkopa:import-historical-penalty-report first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $linked = $ambiguous = $unmatched = $blank = 0;
        $unmatchedNames = [];

        foreach ($reports->groupBy('company_id') as $companyId => $companyReports) {
            /** @var Collection<string, Collection<int, Customer>> $byKey */
            $byKey = Customer::query()->where('company_id', $companyId)->get()
                ->groupBy(fn (Customer $customer): string => HistoricalNameMatcher::key($customer->full_name));

            DB::transaction(function () use ($companyReports, $byKey, $dryRun, &$linked, &$ambiguous, &$unmatched, &$blank, &$unmatchedNames): void {
                foreach ($companyReports as $report) {
                    foreach ($report->records as $record) {
                        if (trim((string) $record->customer_name) === '') {
                            $blank++;

                            continue;
                        }
                        $candidates = ($byKey->get(HistoricalNameMatcher::key($record->customer_name)) ?? collect())
                            ->filter(fn (Customer $customer): bool => HistoricalNameMatcher::same($record->customer_name, $customer->full_name));
                        $sameBranch = $candidates->where('branch_id', $report->branch_id);
                        if ($sameBranch->count() === 1) {
                            $candidates = $sameBranch;
                        }

                        match (true) {
                            $candidates->count() === 1 => $this->link($record, $candidates->first(), $dryRun, $linked),
                            $candidates->count() > 1 => $ambiguous++,
                            default => $this->miss($record, $unmatched, $unmatchedNames),
                        };
                    }
                }
            });
        }

        $this->info(sprintf(
            '%s %d of %d rows to a customer. %d matched no customer, %d matched more than one, %d have no name on the printout.',
            $dryRun ? 'Would link' : 'Linked',
            $linked,
            $reports->sum(fn (HistoricalPenaltyReport $report): int => $report->records->count()),
            $unmatched,
            $ambiguous,
            $blank,
        ));
        if ($unmatchedNames !== []) {
            $this->line('Not matched (no customer of that name yet): '.implode(', ', array_slice(array_keys($unmatchedNames), 0, 20)).(count($unmatchedNames) > 20 ? ', …' : ''));
        }

        return self::SUCCESS;
    }

    private function link(HistoricalPenaltyRecord $record, Customer $customer, bool $dryRun, int &$linked): void
    {
        $linked++;
        if (! $dryRun && $record->customer_id !== $customer->id) {
            $record->update(['customer_id' => $customer->id]);
        }
    }

    /**
     * @param  array<string, true>  $names
     */
    private function miss(HistoricalPenaltyRecord $record, int &$unmatched, array &$names): void
    {
        $unmatched++;
        $names[$record->customer_name] = true;
    }
}

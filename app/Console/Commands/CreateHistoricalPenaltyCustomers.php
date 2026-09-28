<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\HistoricalPenaltyRecord;
use App\Models\HistoricalPenaltyReport;
use App\Services\Customers\CustomerRegistrar;
use App\Services\Customers\HistoricalNameMatcher;
use App\Services\Customers\KycStatusCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Creates a customer for each person who appears on an imported historical Penalty report and is not in the system
 * yet, and links every row to them. Run after mkopa:import-historical-penalty-report and
 * mkopa:link-historical-penalties.
 *
 * - No duplicates: a row whose name already matches a customer is linked to that customer, never to a new one
 *   (matching is company-wide via {@see HistoricalNameMatcher}, so a person penalised in two branches is one
 *   customer). People are grouped on the printed name, so the same name on several rows is one customer. A name that
 *   matches more than one existing customer is left alone and reported — merging is a person's decision.
 * - A Penalty report prints no phone number, so these customers have none; their remark says where they came from and
 *   that registration must be completed before any new loan. Gender and date of birth are not invented.
 * - A report whose branch does not exist in the system (its rows have no branch to belong to) is skipped and reported.
 *
 * Safe to run again: it creates nothing the second time.
 */
class CreateHistoricalPenaltyCustomers extends Command
{
    protected $signature = 'mkopa:create-historical-penalty-customers
        {--company= : Company id (defaults to every company)}
        {--report= : Only this report code}
        {--dry-run : List what would be created without writing}';

    protected $description = 'Create the customers of imported historical Penalty reports, one per person, without duplicates';

    public function handle(CustomerRegistrar $registrar, KycStatusCalculator $kyc): int
    {
        $reports = HistoricalPenaltyReport::query()
            ->when($this->option('company') !== null, fn ($query) => $query->where('company_id', (int) $this->option('company')))
            ->when($this->option('report') !== null, fn ($query) => $query->where('code', $this->option('report')))
            ->with(['records' => fn ($query) => $query->orderBy('serial_number')])
            ->orderBy('branch_name')
            ->get();

        if ($reports->isEmpty()) {
            $this->error('No historical Penalty report matches; run mkopa:import-historical-penalty-report first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $counts = ['created' => 0, 'reused' => 0, 'ambiguous' => 0, 'blank' => 0, 'no_branch' => 0];

        foreach ($reports->groupBy('company_id') as $companyId => $companyReports) {
            /** @var Collection<string, Collection<int, Customer>> $byKey */
            $byKey = Customer::query()->where('company_id', (int) $companyId)->get()
                ->groupBy(fn (Customer $customer): string => HistoricalNameMatcher::key($customer->full_name));

            DB::transaction(function () use ($companyReports, $byKey, $registrar, $kyc, $dryRun, &$counts): void {
                foreach ($companyReports as $report) {
                    if ($report->branch_id === null) {
                        $counts['no_branch'] += $report->records->count();
                        $this->warn("{$report->branch_name}: no such branch, so its {$report->records->count()} rows get no customer. Create the branch, re-run the import, then run this again.");

                        continue;
                    }

                    $people = $report->records
                        ->filter(fn (HistoricalPenaltyRecord $record): bool => trim((string) $record->customer_name) !== '')
                        ->groupBy(fn (HistoricalPenaltyRecord $record): string => implode(' ', HistoricalNameMatcher::parts($record->customer_name)));
                    $counts['blank'] += $report->records->count() - $people->flatten()->count();

                    foreach ($people as $name => $records) {
                        /** @var Collection<int, HistoricalPenaltyRecord> $records */
                        $serials = $records->pluck('serial_number')->implode(', ');
                        $candidates = ($byKey->get(HistoricalNameMatcher::key($name)) ?? collect())
                            ->filter(fn (Customer $customer): bool => HistoricalNameMatcher::same($name, $customer->full_name));

                        if ($candidates->count() > 1) {
                            $counts['ambiguous']++;
                            $this->warn(sprintf('? %s (%s S/No. %s) matches %d customers (%s); left unlinked for you to decide.', $name, $report->branch_name, $serials, $candidates->count(), $candidates->map(fn (Customer $c): string => $c->customer_number)->implode(', ')));

                            continue;
                        }

                        $customer = $candidates->first();
                        if ($customer !== null) {
                            $counts['reused']++;
                        } else {
                            $counts['created']++;
                            $this->line(sprintf('+ %s (%s S/No. %s)', $name, $report->branch_name, $serials));
                            if ($dryRun) {
                                continue;
                            }
                            $customer = $this->create($name, $report, $serials, $registrar, $kyc);
                            $byKey->put(HistoricalNameMatcher::key($name), ($byKey->get(HistoricalNameMatcher::key($name)) ?? collect())->push($customer));
                        }

                        if (! $dryRun) {
                            HistoricalPenaltyRecord::query()->whereKey($records->modelKeys())->update(['customer_id' => $customer->id]);
                        }
                    }
                }
            });
        }

        $this->info(sprintf(
            '%s%d customers created, %d already existed, %d matched more than one customer, %d rows have no name on the printout, %d rows belong to a branch that does not exist.',
            $dryRun ? '[dry run] ' : '',
            $counts['created'],
            $counts['reused'],
            $counts['ambiguous'],
            $counts['blank'],
            $counts['no_branch'],
        ));

        return self::SUCCESS;
    }

    /**
     * A customer with the printed name and nothing invented: no phone (the report prints none), no gender, no date of
     * birth, KYC incomplete and pending approval, so registration must be completed before any new loan.
     */
    private function create(string $name, HistoricalPenaltyReport $report, string $serials, CustomerRegistrar $registrar, KycStatusCalculator $kyc): Customer
    {
        $parts = HistoricalNameMatcher::parts($name);
        $last = array_pop($parts) ?? '';
        $first = array_shift($parts) ?? $last;

        $customer = new Customer;
        $customer->forceFill([
            'company_id' => $report->company_id,
            'branch_id' => $report->branch_id,
            'customer_number' => $registrar->nextCustomerNumber(),
            'first_name' => $first,
            'middle_name' => $parts === [] ? null : implode(' ', $parts),
            'last_name' => $last,
            'status' => 'pending',
            'account_status' => 'active',
            'approval_status' => 'pending',
            'kyc_status' => KycStatusCalculator::INCOMPLETE,
            'registration_source' => CreateHistoricalCustomers::SOURCE,
            'status_remarks' => sprintf('Created from %s, S/No. %s. The Penalty report prints no phone number, gender or date of birth: complete registration before any new loan.', $report->label(), $serials),
        ])->save();
        $kyc->refresh($customer);
        $registrar->audit($customer, 'Customer.imported', [
            'customer_number' => $customer->customer_number,
            'source' => $report->label(),
            'serial_numbers' => $report->records->whereIn('serial_number', explode(', ', $serials))->pluck('serial_number')->all(),
        ]);

        return $customer;
    }
}

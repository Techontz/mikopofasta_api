<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\HistoricalFileRecord;
use App\Models\HistoricalFileReport;
use App\Services\Customers\CustomerRegistrar;
use App\Services\Customers\KycStatusCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates one customer per distinct person on an imported historical File report (user ruling 2026-09-18) and links
 * every report row to that customer. Run after mkopa:import-historical-file-report.
 *
 * - One customer per name: rows are grouped on the printed name (case, spacing and dots ignored), with the manifest's
 *   customer_aliases folding known spelling variants together. An existing customer of the company with the same name
 *   is reused, never duplicated; rows already linked keep their customer. Running again creates nothing new.
 * - Phone: the printed number, unless another customer already holds it (the report gives one number to several
 *   people). Then the earliest customer in the report keeps it; the others get no phone, the number in
 *   alternative_phone and a remark to confirm their own number.
 * - Gender and date of birth are not printed, so they stay empty. Customers are KYC "incomplete" (pending approval,
 *   registration source historical_import) and must complete registration before any new loan.
 */
class CreateHistoricalCustomers extends Command
{
    public const SOURCE = 'historical_import';

    protected $signature = 'mkopa:create-historical-customers
        {manifest=database/data/historical/kakonko-2022-file-report.json : Report manifest (JSON), relative to the API root}
        {--company= : Company id (defaults to the only company)}
        {--dry-run : List what would be created without writing}';

    protected $description = 'Create the customers of an imported historical File report, one per person, without duplicates';

    public function handle(CustomerRegistrar $registrar, KycStatusCalculator $kyc): int
    {
        try {
            $path = str_starts_with((string) $this->argument('manifest'), DIRECTORY_SEPARATOR) ? (string) $this->argument('manifest') : base_path((string) $this->argument('manifest'));
            $manifest = json_decode((string) @file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $report = HistoricalFileReport::query()
                ->when($this->option('company') !== null, fn ($query) => $query->where('company_id', (int) $this->option('company')))
                ->where('code', $manifest['code'])
                ->with(['records' => fn ($query) => $query->orderBy('serial_number')])
                ->get();
            if ($report->count() !== 1) {
                throw new RuntimeException($report->isEmpty()
                    ? "Report {$manifest['code']} has not been imported; run mkopa:import-historical-file-report first."
                    : 'The report exists in more than one company; pass --company=<id>.');
            }
            $report = $report->first();
            if ($report->branch_id === null) {
                throw new RuntimeException("Report {$report->code} has no branch.");
            }
        } catch (RuntimeException|\JsonException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $aliases = collect($manifest['customer_aliases'] ?? [])->mapWithKeys(fn (string $to, string $from): array => [self::normalise($from) => self::normalise($to)]);
        $people = $report->records->groupBy(fn (HistoricalFileRecord $record): string => $aliases->get(self::normalise($record->customer_name), self::normalise($record->customer_name)));
        $dryRun = (bool) $this->option('dry-run');
        $counts = ['created' => 0, 'reused' => 0, 'without_phone' => 0];

        DB::transaction(function () use ($people, $report, $registrar, $kyc, $dryRun, &$counts): void {
            $existing = Customer::query()->where('company_id', $report->company_id)->get()
                ->groupBy(fn (Customer $customer): string => self::normalise($customer->full_name));
            $phoneHolders = Customer::withTrashed()->whereNotNull('phone')->pluck('id', 'phone');

            foreach ($people as $name => $records) {
                /** @var Collection<int, HistoricalFileRecord> $records */
                $printedPhone = $records->first()->phone;
                $customer = collect([$records->firstWhere('customer_id', '!=', null)?->customer])
                    ->concat($existing->get($name) ?? [])
                    ->filter()
                    ->first(fn (Customer $candidate): bool => self::isSamePerson($candidate, $printedPhone));
                $serials = $records->pluck('serial_number')->implode(', ');

                if ($customer !== null) {
                    $counts['reused']++;
                    $this->line("= {$customer->customer_number} {$customer->full_name} (S/No. {$serials})");
                } else {
                    $printed = $records->first();
                    $phone = $printed->phone;
                    $holder = $phone !== null ? $phoneHolders->get($phone) : null;
                    $remarks = sprintf('Created from %s, S/No. %s. Gender and date of birth are not on the report: complete registration before any new loan.', $report->label(), $serials);
                    if ($holder !== null) {
                        $remarks .= " The printed phone {$phone} belongs to another customer (".(Customer::withTrashed()->find($holder)?->full_name ?? "#{$holder}").'); confirm this customer\'s own number.';
                        $counts['without_phone']++;
                    }

                    $counts['created']++;
                    $this->line(sprintf('+ %s | %s%s (S/No. %s)', $printed->customer_name, $holder === null ? $phone : 'no phone', $holder === null ? '' : " (alt {$phone})", $serials));
                    if ($dryRun) {
                        if ($holder === null && $phone !== null) {
                            $phoneHolders->put($phone, 0);
                        }

                        continue;
                    }

                    [$first, $middle, $last] = self::splitName($printed->customer_name);
                    $customer = new Customer;
                    $customer->forceFill([
                        'company_id' => $report->company_id,
                        'branch_id' => $report->branch_id,
                        'customer_number' => $registrar->nextCustomerNumber(),
                        'first_name' => $first,
                        'middle_name' => $middle,
                        'last_name' => $last,
                        'phone' => $holder === null ? $phone : null,
                        'alternative_phone' => $holder === null ? null : $phone,
                        'status' => 'pending',
                        'account_status' => 'active',
                        'approval_status' => 'pending',
                        'kyc_status' => KycStatusCalculator::INCOMPLETE,
                        'registration_source' => self::SOURCE,
                        'status_remarks' => $remarks,
                    ])->save();
                    $kyc->refresh($customer);
                    $registrar->audit($customer, 'Customer.imported', [
                        'customer_number' => $customer->customer_number,
                        'source' => $report->label(),
                        'serial_numbers' => $records->pluck('serial_number')->all(),
                    ]);

                    if ($customer->phone !== null) {
                        $phoneHolders->put($customer->phone, $customer->id);
                    }
                    $existing->put($name, ($existing->get($name) ?? collect())->push($customer));
                }

                if (! $dryRun) {
                    HistoricalFileRecord::query()->whereKey($records->modelKeys())->update(['customer_id' => $customer->id]);
                }
            }

        });

        $this->info(sprintf('%s%d customers created (%d without a phone of their own), %d already existed; %d report rows, %d people.', $dryRun ? '[dry run] ' : '', $counts['created'], $counts['without_phone'], $counts['reused'], $report->records->count(), $people->count()));

        return self::SUCCESS;
    }

    /**
     * Whether a customer of the same printed name is the same person as the one on this row, judged on the phone
     * number the report prints beside the name.
     *
     * Two people really do share a name across branches — FESTO E. NYAGAWA borrows at Makambako on 255655995363 and
     * another FESTO E. NYAGAWA at Wanging'ombe on 255764587402 — and merging them files one of them under the other's
     * branch. So a customer is only reused when the printed number is one of theirs; a different number means a
     * different person and a customer of their own. A row or a customer with no number keeps the old behaviour of
     * matching on the name alone, because that is all either of them has.
     */
    private static function isSamePerson(Customer $customer, ?string $printedPhone): bool
    {
        $theirs = array_filter([$customer->phone, $customer->alternative_phone]);

        return $printedPhone === null || $theirs === [] || in_array($printedPhone, $theirs, true);
    }

    /**
     * Upper-case name without dots and with single spaces, so "RIZIKI . JORAM" matches "Riziki Joram" and
     * "JUMA K. JUMA" matches a customer registered as "Juma K Juma".
     */
    public static function normalise(string $name): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace('.', ' ', strtoupper($name))));
    }

    /**
     * "MARCO N. BILAGAMBALAYE" → [MARCO, N., BILAGAMBALAYE]; "SESI LIA A. AYUBU" → [SESI LIA, A., AYUBU];
     * "RIZIKI . JORAM" → [RIZIKI, null, JORAM]. The last word is the surname, initials are the middle name.
     *
     * @return array{0: string, 1: string|null, 2: string}
     */
    public static function splitName(string $printed): array
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim($printed)) ?: [], fn (string $word): bool => $word !== '.'));
        $last = array_pop($words) ?? '';
        $initials = array_values(array_filter($words, fn (string $word): bool => (bool) preg_match('/^[A-Z]\.?$/i', $word)));
        $first = array_values(array_filter($words, fn (string $word): bool => ! preg_match('/^[A-Z]\.?$/i', $word)));

        return [$first === [] ? $last : implode(' ', $first), $initials === [] ? null : implode(' ', $initials), $last];
    }
}

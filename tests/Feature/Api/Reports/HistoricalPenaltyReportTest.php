<?php

namespace Tests\Feature\Api\Reports;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\HistoricalPenaltyRecord;
use App\Models\HistoricalPenaltyReport;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\Penalty;
use App\Services\Customers\HistoricalNameMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The branch "PENARTY REPORT"s printed by the old system on 2026-09-20, imported as history: records only, shown on
 * Report → Penalty, never as penalties, payments, cash or ledger entries.
 */
class HistoricalPenaltyReportTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private const KAKONKO = 'database/data/historical/kakonko-penalty-report.json';

    private const IGOMA = 'database/data/historical/igoma-penalty-report.json';

    private const RURENGE = 'database/data/historical/rurenge-penalty-report.json';

    private Branch $kakonko;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-21 10:00:00');
        $this->admin = $this->signInAdmin();
        $this->kakonko = Branch::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Kakonko']);
    }

    public function test_import_stores_the_report_as_printed_without_posting_anything(): void
    {
        $this->artisan('mkopa:import-historical-penalty-report', ['manifest' => self::KAKONKO])->assertSuccessful();
        $this->artisan('mkopa:import-historical-penalty-report', ['manifest' => self::KAKONKO])->assertSuccessful();

        $this->assertSame(43, HistoricalPenaltyRecord::count());
        $this->assertSame(0, Penalty::count());
        $this->assertSame(0, Loan::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, JournalEntry::count());

        $report = HistoricalPenaltyReport::sole();
        $this->assertSame('PENARTY REPORT - KAKONKO', $report->title);
        $this->assertSame($this->kakonko->id, $report->branch_id);
        $this->assertSame('2026-09-20', $report->printed_on->toDateString());
        $this->assertSame('1178556.00', $report->printed_total);
        $this->assertEqualsWithDelta(1178556.0, (float) HistoricalPenaltyRecord::sum('penalty_amount'), 0.01);

        $waiver = HistoricalPenaltyRecord::where('serial_number', 5)->sole();
        $this->assertSame(['LEONADRD PETRO BAMPAMA', '-1250.00', '2024-11-05'], [$waiver->customer_name, $waiver->penalty_amount, $waiver->penalty_date->toDateString()]);
    }

    public function test_a_row_without_a_name_and_a_zero_loan_are_kept_as_printed(): void
    {
        $this->branch('Igoma');
        $this->artisan('mkopa:import-historical-penalty-report', ['manifest' => self::IGOMA])->assertSuccessful();

        $blank = HistoricalPenaltyRecord::where('serial_number', 6)->sole();
        $this->assertSame('LAMECK BENEZETI ALPHONCE', $blank->customer_name);
        $this->assertSame('0.00', $blank->loan_amount);
        $this->assertSame('457.00', $blank->penalty_amount);
    }

    public function test_a_report_whose_branch_does_not_exist_is_kept_without_one(): void
    {
        $this->artisan('mkopa:import-historical-penalty-report', ['manifest' => self::RURENGE])->assertSuccessful();

        $report = HistoricalPenaltyReport::sole();
        $this->assertNull($report->branch_id);
        $this->assertSame('RURENGE', $report->branch_name);
        $this->assertSame(6, $report->records()->count());

        $this->assertCount(6, $this->getJson(route('api.v1.reports.penalties'))->assertOk()->json('data.rows'));
        $this->actingAs($this->employeeWithRole('branch_manager', $this->kakonko->id));
        $this->getJson(route('api.v1.reports.penalties'))->assertOk()->assertJsonCount(0, 'data.rows');
    }

    public function test_penalty_report_lists_the_historical_rows_with_their_printed_total(): void
    {
        $this->artisan('mkopa:import-historical-penalty-report', ['manifest' => self::KAKONKO])->assertSuccessful();

        $data = $this->getJson(route('api.v1.reports.penalties'))->assertOk()->json('data');

        $this->assertCount(43, $data['rows']);
        $this->assertEqualsWithDelta(1178556.0, (float) $data['totals']['penalty_amount'], 0.01);
        $this->assertSame(0.0, (float) $data['totals']['paid_amount']);
        $this->assertSame(1178556.0, (float) $data['historical'][0]['printed_total']);
        $this->assertSame('kakonko Penalty Report 2026-09-20.pdf', $data['historical'][0]['source_document']);

        $row = collect($data['rows'])->firstWhere('serial_number', 11);
        $this->assertTrue($row['historical']);
        $this->assertSame(['LUCAS MANINGU KASUBI', 'KAKONKO', '2026-09-05'], [$row['customer'], $row['branch'], $row['penalty_date']]);
        $this->assertEqualsWithDelta([5985280.0, 109376.0], [(float) $row['loan_amount'], (float) $row['penalty_amount']], 0.01);
        $this->assertNull($row['loan_id']);

        $this->assertSame('2026-09-05', $data['rows'][0]['penalty_date']);
        $this->assertSame('2023-10-07', end($data['rows'])['penalty_date']);
    }

    public function test_the_date_filter_narrows_the_historical_rows(): void
    {
        $this->artisan('mkopa:import-historical-penalty-report', ['manifest' => self::KAKONKO])->assertSuccessful();

        $rows = $this->getJson(route('api.v1.reports.penalties', ['from' => '2023-01-01', 'to' => '2023-12-31']))->assertOk()->json('data.rows');

        // Only the four 2023 rows, newest penalty first (rows 2 and 3 share a date, so their order between them is not fixed).
        $serials = array_column($rows, 'serial_number');
        sort($serials);
        $this->assertSame([1, 2, 3, 4], $serials);
        $this->assertSame(['2023-10-27', '2023-10-16', '2023-10-16', '2023-10-07'], array_column($rows, 'penalty_date'));
    }

    public function test_rows_are_linked_to_customers_by_name_without_creating_any(): void
    {
        $this->artisan('mkopa:import-historical-penalty-report', ['manifest' => self::KAKONKO])->assertSuccessful();
        $lucas = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->kakonko->id, 'first_name' => 'LUCAS', 'middle_name' => 'M.', 'last_name' => 'KASUBI']);
        $before = Customer::count();

        $this->artisan('mkopa:link-historical-penalties')->assertSuccessful();
        $this->artisan('mkopa:link-historical-penalties')->assertSuccessful();

        $this->assertSame($before, Customer::count());
        $this->assertSame($lucas->id, HistoricalPenaltyRecord::where('serial_number', 11)->value('customer_id'));
        $this->assertSame(42, HistoricalPenaltyRecord::whereNull('customer_id')->count());

        $row = collect($this->getJson(route('api.v1.reports.penalties'))->json('data.rows'))->firstWhere('serial_number', 11);
        $this->assertSame($lucas->id, $row['customer_id']);
    }

    public function test_customers_are_created_once_per_person_without_duplicating_existing_ones(): void
    {
        $this->artisan('mkopa:import-historical-penalty-report', ['manifest' => self::KAKONKO])->assertSuccessful();
        $lucas = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->kakonko->id, 'first_name' => 'LUCAS', 'middle_name' => 'M.', 'last_name' => 'KASUBI']);

        $this->artisan('mkopa:create-historical-penalty-customers')->assertSuccessful();
        $this->artisan('mkopa:create-historical-penalty-customers')->assertSuccessful();

        // 43 rows, but LEONADRD PETRO BAMPAMA, LAZARO BUKURU JACKSON and STEPHEN MAGORI NYAMASA are each penalised
        // twice, and LUCAS MANINGU KASUBI is the customer that already existed.
        $this->assertSame(39, Customer::where('registration_source', 'historical_import')->count());
        $this->assertSame(40, Customer::count());
        $this->assertSame(0, HistoricalPenaltyRecord::whereNull('customer_id')->count());
        $this->assertSame([9, 31], HistoricalPenaltyRecord::where('customer_id', Customer::where('last_name', 'JACKSON')->sole()->id)->orderBy('serial_number')->pluck('serial_number')->all());

        $this->assertSame($lucas->id, HistoricalPenaltyRecord::where('serial_number', 11)->value('customer_id'));
        $this->assertSame(1, Customer::where('last_name', 'KASUBI')->count());

        $created = Customer::where('last_name', 'MASAGA')->sole();
        $this->assertSame(['MUGETA', 'MAIGA', 'MASAGA'], [$created->first_name, $created->middle_name, $created->last_name]);
        $this->assertSame([null, null, null, 'incomplete', 'pending'], [$created->phone, $created->gender, $created->date_of_birth, $created->kyc_status, $created->approval_status]);
        $this->assertStringContainsString('prints no phone number', $created->status_remarks);
        $this->assertSame($this->kakonko->id, $created->branch_id);
        $this->assertSame(0, Loan::count());
    }

    public function test_a_person_penalised_in_two_branches_is_one_customer(): void
    {
        $this->branch('Makambako');
        $this->artisan('mkopa:import-historical-penalty-report', ['manifest' => self::KAKONKO])->assertSuccessful();
        $this->artisan('mkopa:import-historical-penalty-report', ['manifest' => 'database/data/historical/makambako-penalty-report.json'])->assertSuccessful();

        $this->artisan('mkopa:create-historical-penalty-customers')->assertSuccessful();

        $this->assertSame(0, HistoricalPenaltyRecord::whereNull('customer_id')->count());
        $this->assertSame(
            Customer::count(),
            Customer::query()->get()->map(fn (Customer $customer): string => HistoricalNameMatcher::key($customer->full_name).'|'.$customer->full_name)->unique()->count(),
        );
    }

    public function test_a_report_without_a_branch_gets_no_customers(): void
    {
        $this->artisan('mkopa:import-historical-penalty-report', ['manifest' => self::RURENGE])->assertSuccessful();

        $this->artisan('mkopa:create-historical-penalty-customers')
            ->expectsOutputToContain('RURENGE: no such branch')
            ->assertSuccessful();

        $this->assertSame(0, Customer::count());
        $this->assertSame(6, HistoricalPenaltyRecord::whereNull('customer_id')->count());
    }

    public function test_a_penalty_goes_to_the_namesake_of_its_own_branch(): void
    {
        $makambako = $this->branch('Makambako');
        $this->artisan('mkopa:import-historical-penalty-report', ['manifest' => 'database/data/historical/makambako-penalty-report.json'])->assertSuccessful();
        // Two people share this name across branches; the Makambako penalties are the Makambako one's.
        $theirs = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $makambako->id, 'first_name' => 'FESTO', 'middle_name' => 'E.', 'last_name' => 'NYAGAWA']);
        $namesake = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->kakonko->id, 'first_name' => 'FESTO', 'middle_name' => 'E.', 'last_name' => 'NYAGAWA']);

        $this->artisan('mkopa:link-historical-penalties')->assertSuccessful();

        $rows = HistoricalPenaltyRecord::whereIn('serial_number', [7, 28, 44])->get();
        $this->assertSame([$theirs->id], $rows->pluck('customer_id')->unique()->values()->all());
        $this->assertSame(0, HistoricalPenaltyRecord::where('customer_id', $namesake->id)->count());
    }

    public function test_the_report_needs_permission(): void
    {
        $this->actingAs($this->employeeWithRole('loan_officer'));

        $this->getJson(route('api.v1.reports.penalties'))->assertForbidden();
    }

    private function branch(string $name): Branch
    {
        return Branch::factory()->create(['company_id' => $this->admin->company_id, 'name' => $name]);
    }
}

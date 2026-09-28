<?php

namespace Tests\Feature\Api\Reports;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\HistoricalFilePayment;
use App\Models\HistoricalFileRecord;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Kakonko Branch File report for 2022 (KAKONKO.pdf) imported as history: records only, shown on the File report and
 * its Historical Payments page, never as loans, cash or ledger entries.
 */
class HistoricalFileReportTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private Branch $kakonko;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-18 10:00:00');
        $this->admin = $this->signInAdmin();
        $this->kakonko = Branch::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Kakonko']);
    }

    public function test_import_stores_the_report_as_printed_without_posting_anything(): void
    {
        $this->artisan('mkopa:import-historical-file-report')->assertSuccessful();
        $this->artisan('mkopa:import-historical-file-report')->assertSuccessful();

        $this->assertSame(348, HistoricalFileRecord::count());
        $this->assertSame(798, HistoricalFilePayment::count());
        $this->assertSame(0, Loan::count());
        $this->assertSame(0, LoanTransaction::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, JournalEntry::count());

        $overpaid = HistoricalFileRecord::where('serial_number', 41)->firstOrFail();
        $this->assertSame('ADRIANO B. RUHATA', $overpaid->customer_name);
        $this->assertSame('-740000.00', $overpaid->remain_amount);
        $this->assertSame('2022-01-08', $overpaid->withdrawal_date->toDateString());
        $this->assertNull(HistoricalFileRecord::where('serial_number', 308)->value('status'));
        $this->assertNull(HistoricalFileRecord::where('serial_number', 307)->value('duration_type'));
        $this->assertSame('0766537631', HistoricalFileRecord::where('serial_number', 87)->value('phone'));
    }

    public function test_file_report_shows_the_historical_rows_with_their_monthly_amounts(): void
    {
        $this->artisan('mkopa:import-historical-file-report')->assertSuccessful();

        $data = $this->getJson(route('api.v1.reports.file', ['year' => 2022, 'loan_status' => 'ALL']))->assertOk()->json('data');

        $this->assertCount(348, $data['rows']);
        $this->assertContains(2022, $data['years']);
        $this->assertSame(range(1, 12), array_column($data['months'], 'number'));
        $this->assertSame(55879424.0, (float) $data['totals']['month_1']);
        $this->assertSame(113314762, $data['historical'][0]['printed_totals']['1']);
        $this->assertSame('KAKONKO.pdf', $data['historical'][0]['source_document']);

        $row = collect($data['rows'])->firstWhere('serial_number', 3);
        $this->assertTrue($row['historical']);
        $this->assertSame('KAKONKO', $row['branch']);
        $this->assertSame('RAPHAEL L. RUTANA', $row['customer']);
        $this->assertSame('Monthly', $row['duration']);
        $this->assertSame(6, $row['sessions']);
        $this->assertEquals(['1' => 380000, '2' => 473000, '4' => 826990], $row['months']);
        $this->assertSame('Done', $row['status']);

        $defaults = $this->getJson(route('api.v1.reports.file', ['year' => 2022, 'loan_status' => 'DEFAULT']))->json('data.rows');
        $this->assertSame([12, 15, 44, 51, 316, 320, 346], array_column($defaults, 'serial_number'));

        $this->getJson(route('api.v1.reports.file', ['year' => 2023]))->assertJsonCount(0, 'data.rows')->assertJsonCount(0, 'data.historical');
    }

    public function test_historical_rows_follow_branch_scope(): void
    {
        $this->artisan('mkopa:import-historical-file-report')->assertSuccessful();
        $other = $this->otherBranch();

        $this->actingAs($this->employeeWithRole('branch_manager', $other->id));
        $this->getJson(route('api.v1.reports.file', ['year' => 2022]))->assertOk()->assertJsonCount(0, 'data.rows');
        $this->getJson(route('api.v1.reports.file.historical-payments', ['year' => 2022]))->assertOk()->assertJsonCount(0, 'data.rows');

        $this->actingAs($this->employeeWithRole('branch_manager', $this->kakonko->id));
        $this->getJson(route('api.v1.reports.file', ['year' => 2022]))->assertJsonCount(348, 'data.rows');
    }

    public function test_historical_payments_list_every_month_figure_separately(): void
    {
        $this->artisan('mkopa:import-historical-file-report')->assertSuccessful();

        $data = $this->getJson(route('api.v1.reports.file.historical-payments'))->assertOk()->json('data');

        $this->assertSame(2022, $data['year']);
        $this->assertSame([2022], $data['years']);
        $this->assertCount(798, $data['rows']);
        $this->assertSame(422777673.0, (float) $data['totals']['amount']);
        $this->assertSame(['serial_number' => 1, 'customer' => 'MARCO N. BILAGAMBALAYE', 'month_name' => 'January', 'amount' => 227500], array_intersect_key($data['rows'][0], array_flip(['serial_number', 'customer', 'month_name', 'amount'])));

        $this->actingAs($this->employeeWithRole('loan_officer'));
        $this->getJson(route('api.v1.reports.file.historical-payments'))->assertForbidden();
    }

    public function test_customers_are_created_once_per_person_and_linked_to_every_row(): void
    {
        $this->artisan('mkopa:import-historical-file-report')->assertSuccessful();
        $existing = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->kakonko->id, 'first_name' => 'Juma', 'middle_name' => 'K', 'last_name' => 'Juma', 'phone' => '255687216205']);

        $this->artisan('mkopa:create-historical-customers')->assertSuccessful();
        $this->artisan('mkopa:create-historical-customers')->assertSuccessful();

        $imported = Customer::where('registration_source', 'historical_import');
        $this->assertSame(163, $imported->count());
        $this->assertSame(0, HistoricalFileRecord::whereNull('customer_id')->count());
        $this->assertSame([11, 71, 167], HistoricalFileRecord::where('customer_id', $existing->id)->orderBy('serial_number')->pluck('serial_number')->all());
        $this->assertSame(0, Loan::count());

        $witnes = Customer::where('last_name', 'KAGIRIGIRI')->sole();
        $this->assertSame(['WITNES', 'M.', '255743947467'], [$witnes->first_name, $witnes->middle_name, $witnes->phone]);
        $this->assertSame([6, 48, 184, 282], HistoricalFileRecord::where('customer_id', $witnes->id)->orderBy('serial_number')->pluck('serial_number')->all());

        $keeper = Customer::where('last_name', 'AYUBU')->where('first_name', 'GHATI')->sole();
        $sharer = Customer::where('last_name', 'NDAYANSE')->sole();
        $this->assertSame('255759647427', $keeper->phone);
        $this->assertNull($sharer->phone);
        $this->assertSame('255759647427', $sharer->alternative_phone);
        $this->assertStringContainsString('GHATI A. AYUBU', $sharer->status_remarks);
        $this->assertSame(7, $imported->clone()->whereNull('phone')->count());

        $riziki = Customer::where('last_name', 'JORAM')->sole();
        $this->assertSame(['RIZIKI', null, null, null, 'incomplete'], [$riziki->first_name, $riziki->middle_name, $riziki->gender, $riziki->date_of_birth, $riziki->kyc_status]);
        $this->assertSame('SESI LIA', Customer::where('last_name', 'AYUBU')->where('phone', '255683825261')->value('first_name'));
        $this->assertSame(163, AuditLog::where('action', 'Customer.imported')->count());

        $row = collect($this->getJson(route('api.v1.reports.file', ['year' => 2022]))->json('data.rows'))->firstWhere('serial_number', 26);
        $this->assertSame($sharer->id, $row['customer_id']);
    }

    public function test_a_namesake_on_another_phone_number_gets_their_own_customer_in_their_own_branch(): void
    {
        $this->artisan('mkopa:import-historical-file-report')->assertSuccessful();
        $other = $this->otherBranch();
        // The same printed name as S/No. 11, 71 and 167, but the report prints a different number beside it, so this
        // is a different person who happens to share the name — as FESTO E. NYAGAWA does at Makambako and Wanging'ombe.
        $namesake = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $other->id, 'first_name' => 'Juma', 'middle_name' => 'K', 'last_name' => 'Juma', 'phone' => '255700000001']);

        $this->artisan('mkopa:create-historical-customers')->assertSuccessful();

        $rows = HistoricalFileRecord::whereIn('serial_number', [11, 71, 167])->get();
        $this->assertSame(0, $rows->where('customer_id', $namesake->id)->count(), 'the namesake on another number must not collect these rows');

        $mine = Customer::query()->where('last_name', 'JUMA')->where('phone', '255687216205')->sole();
        $this->assertSame($this->kakonko->id, $mine->branch_id);
        $this->assertSame([11, 71, 167], $rows->sortBy('serial_number')->pluck('serial_number')->values()->all());
        $this->assertSame([$mine->id], $rows->pluck('customer_id')->unique()->values()->all());
        $this->assertSame($other->id, $namesake->refresh()->branch_id, 'the namesake keeps their own branch');
    }

    public function test_later_reports_reuse_the_customers_of_earlier_ones(): void
    {
        $manifest2023 = 'database/data/historical/kakonko-2023-file-report.json';
        $this->artisan('mkopa:import-historical-file-report')->assertSuccessful();
        $this->artisan('mkopa:import-historical-file-report', ['manifest' => $manifest2023])->assertSuccessful();
        $this->artisan('mkopa:create-historical-customers')->assertSuccessful();
        $this->artisan('mkopa:create-historical-customers', ['manifest' => $manifest2023])->assertSuccessful();

        $this->assertSame(199, Customer::count());
        $this->assertSame(0, HistoricalFileRecord::whereNull('customer_id')->count());
        $this->assertSame(1, Customer::where('last_name', 'KAGIRIGIRI')->count());
        $this->assertSame(3, HistoricalFileRecord::where('customer_id', Customer::where('first_name', 'ALFRED')->where('last_name', 'KILOMBA')->value('id'))->whereHas('report', fn ($query) => $query->where('year', 2023))->count());
        $this->assertNull(Customer::where('last_name', 'GIBAKA')->value('phone'));

        $data = $this->getJson(route('api.v1.reports.file', ['year' => 2023]))->assertOk()->json('data');
        $this->assertCount(203, $data['rows']);
        $this->assertSame(141581804, $data['historical'][0]['printed_totals']['7']);
        $this->assertContains(2023, $data['years']);
    }

    public function test_import_refuses_a_company_without_the_branch(): void
    {
        $this->kakonko->update(['name' => 'Kibondo']);

        $this->artisan('mkopa:import-historical-file-report')->assertFailed();
        $this->assertSame(0, HistoricalFileRecord::count());
    }
}

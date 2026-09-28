<?php

namespace Tests\Feature\Api\Imports;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Models\LegacyImport;
use App\Models\LegacyImportRow;
use App\Services\LegacyImports\LegacyCustomerMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * Production regression (2026-09-27): the production MySQL driver returns integer columns as strings ("15"), while the
 * development driver returns integers. Old System Imports compared ids strictly, so on production "15" === 15 was false:
 * "Create New Customer" answered 404 with an empty message and in-branch customers never matched automatically.
 *
 * These tests switch the connection to that behaviour (PDO::ATTR_STRINGIFY_FETCHES) instead of relying on the local driver.
 */
class LegacyImportStringIdsTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'No.,Customer Name,Branch Name,Loan Amount,Interest,Principal + Interest,Paid Amount,Remain Amount,Status,Charges,Date,Alert';

    private Employee $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->branch = Branch::findOrFail($this->admin->branch_id);
        $this->branch->update(['name' => 'MISSENYI']);
        CustomerCategory::factory()->create(['company_id' => $this->admin->company_id]);
    }

    protected function tearDown(): void
    {
        $this->idsAsStrings(false);

        parent::tearDown();
    }

    public function test_the_simulation_really_returns_ids_as_strings(): void
    {
        $this->idsAsStrings();

        $this->assertIsString(DB::table('branches')->where('id', $this->branch->id)->value('company_id'));
    }

    public function test_foreign_keys_of_an_import_and_its_rows_are_integers_even_when_the_driver_returns_strings(): void
    {
        $id = $this->upload("1,JUMA HAMISI MWAKALINGA,MISSENYI,100000,20000,120000,30000,90000,Active,200,2026-08-04,OLD\n");
        $rowId = LegacyImportRow::where('legacy_import_id', $id)->value('id');
        $this->postJson("/api/v1/legacy-imports/{$id}/rows/{$rowId}/map", ['create' => true])->assertOk();
        $this->postJson("/api/v1/legacy-imports/{$id}/submit")->assertOk();

        $this->idsAsStrings();
        $row = LegacyImportRow::findOrFail($rowId);
        $import = LegacyImport::findOrFail($id);

        $this->assertIsString($row->getRawOriginal('legacy_import_id'), 'The driver returned a string…');
        $this->assertSame($id, $row->legacy_import_id, '…and the model gives an integer.');
        $this->assertIsInt($row->customer_id);
        $this->assertSame($this->admin->id, $row->mapped_by);
        foreach (['company_id', 'branch_id', 'uploaded_by', 'submitted_by'] as $column) {
            $this->assertIsInt($import->{$column}, $column);
        }
        foreach (['approved_by', 'rejected_by', 'rolled_back_by'] as $column) {
            $this->assertNull($import->{$column}, $column);
        }
    }

    public function test_creating_a_customer_from_an_unmatched_row_works_when_ids_are_strings(): void
    {
        $id = $this->upload("1,JUMA HAMISI MWAKALINGA,MISSENYI,100000,20000,120000,30000,90000,Active,200,2026-08-04,OLD\n");
        $row = LegacyImportRow::where('legacy_import_id', $id)->firstOrFail();
        $this->assertSame(LegacyImportRow::STATUS_UNMATCHED, $row->status);

        $this->idsAsStrings();
        $detail = $this->postJson("/api/v1/legacy-imports/{$id}/rows/{$row->id}/map", ['create' => true])
            ->assertOk()
            ->assertJsonPath('message', 'Row mapped.')
            ->json('data');
        $this->assertEquals([0, 0, 1], [$detail['unmatched_rows'], $detail['error_rows'], $detail['valid_rows']]);

        $this->assertSame('legacy_import', Customer::findOrFail(LegacyImportRow::findOrFail($row->id)->customer_id)->registration_source);
    }

    public function test_mapping_a_row_to_an_existing_customer_works_when_ids_are_strings(): void
    {
        $id = $this->upload("1,JUMA HAMISI MWAKALINGA,MISSENYI,100000,20000,120000,30000,90000,Active,200,2026-08-04,OLD\n");
        $row = LegacyImportRow::where('legacy_import_id', $id)->firstOrFail();
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id, 'first_name' => 'JUMA', 'middle_name' => 'H', 'last_name' => 'MWAKALINGA']);

        $this->idsAsStrings();
        // The customer's company_id comes back as a string: it must still be recognised as the import's company.
        $detail = $this->postJson("/api/v1/legacy-imports/{$id}/rows/{$row->id}/map", ['customer_id' => $customer->id])
            ->assertOk()
            ->json('data');
        $this->assertEquals(1, $detail['valid_rows']);
        $this->assertSame($customer->id, LegacyImportRow::findOrFail($row->id)->customer_id);
    }

    public function test_the_matcher_finds_a_customer_of_the_branch_when_branch_ids_are_strings(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id, 'first_name' => 'JUMA', 'middle_name' => 'HAMISI', 'last_name' => 'MWAKALINGA', 'phone' => '0712000111']);

        $this->idsAsStrings();
        $byName = (new LegacyCustomerMatcher($this->admin->company_id))->match('JUMA HAMISI MWAKALINGA', null, $this->branch->id);
        $byPhone = (new LegacyCustomerMatcher($this->admin->company_id))->match('JUMA HAMISI MWAKALINGA', '0712000111', $this->branch->id);

        $this->assertSame($customer->id, $byName['customer']?->id);
        $this->assertSame(LegacyImportRow::STATUS_VALID, $byName['status']);
        $this->assertSame(LegacyImportRow::STATUS_VALID, $byPhone['status'], 'Not "registered in another branch".');
        $this->assertNull($byPhone['message']);
    }

    public function test_an_upload_matches_an_existing_branch_customer_when_ids_are_strings(): void
    {
        Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id, 'first_name' => 'JUMA', 'middle_name' => 'HAMISI', 'last_name' => 'MWAKALINGA']);

        $this->idsAsStrings();
        $id = $this->upload("1,JUMA HAMISI MWAKALINGA,MISSENYI,100000,20000,120000,30000,90000,Active,200,2026-08-04,OLD\n");

        // The counts are compared by value: the detail endpoint returns them as the driver gives them (see the report).
        $detail = $this->getJson("/api/v1/legacy-imports/{$id}")->assertOk()->json('data');
        $this->assertEquals([1, 0, 0], [$detail['valid_rows'], $detail['unmatched_rows'], $detail['error_rows']]);
    }

    /**
     * Make the connection return every column as a string, as the production driver does (or back to native types).
     */
    private function idsAsStrings(bool $on = true): void
    {
        DB::connection()->getPdo()->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, $on);
    }

    private function upload(string $rows): int
    {
        return (int) $this->post('/api/v1/legacy-imports', [
            'module' => 'salary_advance',
            'branch_id' => $this->branch->id,
            'file' => UploadedFile::fake()->createWithContent('advances.csv', self::HEADER."\n".$rows),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }
}

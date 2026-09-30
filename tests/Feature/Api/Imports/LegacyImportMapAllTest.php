<?php

namespace Tests\Feature\Api\Imports;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Models\LegacyImportRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Feature\Api\Accounting\AccountingTestHelpers;
use Tests\TestCase;

/**
 * Map All: after an upload, every unmatched row is listed with the customers it could belong to and a proposed choice;
 * the reviewed choices are then applied in one request and one transaction.
 */
class LegacyImportMapAllTest extends TestCase
{
    use AccountingTestHelpers, RefreshDatabase;

    private const HEADER = 'No.,Customer Name,Branch Name,Loan Amount,Interest,Principal + Interest,Paid Amount,Remain Amount,Status,Charges,Date,Alert';

    private Employee $admin;

    private Branch $branch;

    private Branch $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->branch = Branch::findOrFail($this->admin->branch_id);
        $this->branch->update(['name' => 'MISSENYI']);
        $this->otherBranch = Branch::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'LINDI']);
        CustomerCategory::factory()->create(['company_id' => $this->admin->company_id]);
    }

    protected function tearDown(): void
    {
        DB::connection()->getPdo()->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);

        parent::tearDown();
    }

    public function test_suggestions_propose_a_new_customer_the_single_likely_customer_or_a_choice(): void
    {
        $elsewhere = $this->customer('BARAKA ELIA MUSHI', $this->otherBranch);
        $this->customer('NEEMA JOSEPH MSHANA', $this->branch);
        $this->customer('NEEMA JOSEPH MSHANA', $this->branch);
        $this->customer('PETRO JOHN MREMA', $this->branch);
        $id = $this->upload(['JUMA HAMISI MWAKALINGA', 'BARAKA ELIA MUSHI', 'NEEMA JOSEPH MSHANA', 'PETRO JOHN MREMA']);

        $rows = collect($this->getJson("/api/v1/legacy-imports/{$id}/map-suggestions")->assertOk()->json('data'))->keyBy('customer_name');

        $this->assertCount(3, $rows, 'PETRO JOHN MREMA was matched automatically, so it is not listed.');
        $this->assertSame('create', $rows['JUMA HAMISI MWAKALINGA']['suggestion'], 'No likely customer: a new customer is proposed.');
        $this->assertSame([], $rows['JUMA HAMISI MWAKALINGA']['candidates']);
        $this->assertSame($elsewhere->id, $rows['BARAKA ELIA MUSHI']['suggestion'], 'One likely customer (in another branch): proposed.');
        $this->assertStringContainsString('LINDI', $rows['BARAKA ELIA MUSHI']['candidates'][0]['label']);
        $this->assertNull($rows['NEEMA JOSEPH MSHANA']['suggestion'], 'Two likely customers: the user chooses.');
        $this->assertCount(2, $rows['NEEMA JOSEPH MSHANA']['candidates']);
    }

    public function test_map_all_maps_every_chosen_row_in_one_request(): void
    {
        $elsewhere = $this->customer('BARAKA ELIA MUSHI', $this->otherBranch);
        $id = $this->upload(['JUMA HAMISI MWAKALINGA', 'BARAKA ELIA MUSHI', 'REHEMA SAIDI KIBWANA', 'ZAWADI ALLY MAGESA']);
        $rows = LegacyImportRow::where('legacy_import_id', $id)->orderBy('row_number')->pluck('id', 'customer_name');

        $this->postJson("/api/v1/legacy-imports/{$id}/map-all", ['mappings' => [
            ['row_id' => $rows['JUMA HAMISI MWAKALINGA'], 'create' => true],
            ['row_id' => $rows['BARAKA ELIA MUSHI'], 'customer_id' => $elsewhere->id],
            ['row_id' => $rows['REHEMA SAIDI KIBWANA'], 'create' => true],
        ]])
            ->assertOk()
            ->assertJsonPath('message', '3 rows mapped, 2 new customers created.')
            ->assertJsonPath('data.unmatched_rows', 1)
            ->assertJsonPath('data.can_submit', true);

        $mapped = LegacyImportRow::whereKey($rows->all())->get()->keyBy('customer_name');
        $this->assertSame($elsewhere->id, $mapped['BARAKA ELIA MUSHI']->customer_id);
        $this->assertSame(LegacyImportRow::MATCH_MANUAL, $mapped['JUMA HAMISI MWAKALINGA']->match_method);
        $this->assertSame($this->admin->id, $mapped['REHEMA SAIDI KIBWANA']->mapped_by);
        $this->assertSame(LegacyImportRow::STATUS_UNMATCHED, $mapped['ZAWADI ALLY MAGESA']->status, 'A skipped row stays unmatched.');

        $created = Customer::where('registration_source', 'legacy_import')->pluck('customer_number');
        $this->assertCount(2, $created);
        $this->assertCount(2, $created->unique(), 'Each new customer gets its own number.');
    }

    public function test_one_row_that_cannot_be_mapped_stops_the_whole_batch(): void
    {
        $foreign = Customer::factory()->create();
        $id = $this->upload(['JUMA HAMISI MWAKALINGA', 'REHEMA SAIDI KIBWANA']);
        $rows = LegacyImportRow::where('legacy_import_id', $id)->orderBy('row_number')->pluck('id')->all();

        $this->postJson("/api/v1/legacy-imports/{$id}/map-all", ['mappings' => [
            ['row_id' => $rows[0], 'create' => true],
            ['row_id' => $rows[1], 'customer_id' => $foreign->id],
        ]])->assertUnprocessable()->assertJsonPath('message', 'Row 3: customer not found.');

        $this->assertSame(0, LegacyImportRow::whereKey($rows)->whereNotNull('customer_id')->count());
        $this->assertSame(0, Customer::where('registration_source', 'legacy_import')->count(), 'Nothing was created.');
    }

    public function test_rows_of_another_import_are_refused_and_the_list_is_validated(): void
    {
        $first = $this->upload(['JUMA HAMISI MWAKALINGA']);
        $second = $this->upload(['REHEMA SAIDI KIBWANA']);
        $otherRow = LegacyImportRow::where('legacy_import_id', $second)->value('id');

        $this->postJson("/api/v1/legacy-imports/{$first}/map-all", ['mappings' => [['row_id' => $otherRow, 'create' => true]]])->assertUnprocessable();
        $this->postJson("/api/v1/legacy-imports/{$first}/map-all", ['mappings' => []])->assertUnprocessable()->assertJsonValidationErrors(['mappings']);
        $this->postJson("/api/v1/legacy-imports/{$first}/map-all", ['mappings' => [['row_id' => $otherRow]]])->assertUnprocessable()->assertJsonValidationErrors(['mappings.0.customer_id']);
        $this->assertNull(LegacyImportRow::find($otherRow)->customer_id);
    }

    public function test_only_users_who_may_map_rows_can_use_map_all(): void
    {
        $id = $this->upload(['JUMA HAMISI MWAKALINGA']);
        $row = LegacyImportRow::where('legacy_import_id', $id)->value('id');
        $finance = $this->employeeWithRole($this->admin, 'finance'); // uploads files, but may not map rows

        $this->actingAs($finance)->getJson("/api/v1/legacy-imports/{$id}/map-suggestions")->assertForbidden();
        $this->actingAs($finance)->postJson("/api/v1/legacy-imports/{$id}/map-all", ['mappings' => [['row_id' => $row, 'create' => true]]])->assertForbidden();
    }

    public function test_map_all_works_when_the_database_driver_returns_ids_as_strings(): void
    {
        $existing = $this->customer('BARAKA ELIA MUSHI', $this->otherBranch);
        $id = $this->upload(['JUMA HAMISI MWAKALINGA', 'BARAKA ELIA MUSHI']);
        $rows = LegacyImportRow::where('legacy_import_id', $id)->orderBy('row_number')->pluck('id')->all();

        DB::connection()->getPdo()->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        $this->assertSame($existing->id, collect($this->getJson("/api/v1/legacy-imports/{$id}/map-suggestions")->assertOk()->json('data'))->firstWhere('customer_name', 'BARAKA ELIA MUSHI')['suggestion']);
        $this->postJson("/api/v1/legacy-imports/{$id}/map-all", ['mappings' => [
            ['row_id' => $rows[0], 'create' => true],
            ['row_id' => $rows[1], 'customer_id' => $existing->id],
        ]])->assertOk()->assertJsonPath('message', '2 rows mapped, 1 new customer created.');
    }

    private function customer(string $name, Branch $branch): Customer
    {
        [$first, $middle, $last] = explode(' ', $name);

        return Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $branch->id, 'first_name' => $first, 'middle_name' => $middle, 'last_name' => $last]);
    }

    /**
     * @param  list<string>  $names
     */
    private function upload(array $names): int
    {
        $lines = collect($names)->map(fn (string $name, int $index): string => ($index + 1).",{$name},MISSENYI,100000,20000,120000,30000,90000,Active,200,2026-08-04,OLD")->implode("\n");

        return (int) $this->actingAs($this->admin)->post('/api/v1/legacy-imports', [
            'module' => 'salary_advance',
            'branch_id' => $this->branch->id,
            'file' => UploadedFile::fake()->createWithContent('advances.csv', self::HEADER."\n".$lines."\n"),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }
}

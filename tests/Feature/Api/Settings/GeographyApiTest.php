<?php

namespace Tests\Feature\Api\Settings;

use App\Models\District;
use App\Models\Employee;
use App\Models\Region;
use App\Models\Ward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * CUSTOMER_MODULE_IMPLEMENTATION.md §8.2 — Region → District → Ward from the database, idempotent importer.
 */
class GeographyApiTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'geo').'.csv';
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_importer_command_is_idempotent_and_reports_rejected_rows(): void
    {
        Region::create(['name' => 'Dar es salaam']);
        $path = $this->csv("region,district,ward,street\nDar-es-salaam,Ilala,Kariakoo,\nDar-es-salaam,Ilala,Upanga,\nDodoma,Kondoa,\"Dule \"\"M\"\"\",\n,Kondoa,Pahi,\nDodoma,,Pahi,\nDodoma,Kondoa,,\n");

        $this->artisan('geography:import', ['path' => $path])
            ->expectsOutputToContain('Read 6 rows: 3 imported, 3 rejected. Created 1 regions, 2 districts, 3 wards.')
            ->expectsOutputToContain('Line 5: Region is missing.')
            ->assertSuccessful();

        $this->assertSame(2, Region::count(), 'Dar-es-salaam matches the existing Dar es salaam region');
        $this->assertSame(['Kariakoo', 'Upanga'], Ward::whereHas('district', fn ($query) => $query->where('name', 'Ilala'))->orderBy('name')->pluck('name')->all());
        $this->assertTrue(Ward::where('name', 'Dule "M"')->exists());

        $this->artisan('geography:import', ['path' => $path])
            ->expectsOutputToContain('Created 0 regions, 0 districts, 0 wards.')
            ->assertSuccessful();
        $this->assertSame([2, 2, 3], [Region::count(), District::count(), Ward::count()]);

        $this->artisan('geography:import', ['path' => $this->csv("mkoa,wilaya\nX,Y\n")])->assertFailed();
    }

    public function test_bundled_tanzania_geography_imports_real_data_once(): void
    {
        $this->artisan('geography:import')->assertSuccessful();
        $counts = [Region::count(), District::count(), Ward::count()];

        $this->assertSame(31, $counts[0]);
        $this->assertSame(170, $counts[1]);
        $this->assertGreaterThan(3500, $counts[2]);

        $this->artisan('geography:import')->expectsOutputToContain('Created 0 regions, 0 districts, 0 wards.')->assertSuccessful();
        $this->assertSame($counts, [Region::count(), District::count(), Ward::count()]);
    }

    public function test_regions_districts_and_wards_endpoints_follow_the_parent_and_order_by_name(): void
    {
        $admin = $this->signInAdmin();
        $mwanza = Region::create(['name' => 'Mwanza']);
        $arusha = Region::create(['name' => 'Arusha']);
        $nyamagana = District::create(['region_id' => $mwanza->id, 'name' => 'Nyamagana']);
        $ilemela = District::create(['region_id' => $mwanza->id, 'name' => 'Ilemela']);
        District::create(['region_id' => $arusha->id, 'name' => 'Meru']);
        $pamba = Ward::create(['district_id' => $nyamagana->id, 'name' => 'Pamba']);
        Ward::create(['district_id' => $nyamagana->id, 'name' => 'Igogo']);
        Ward::create(['district_id' => $ilemela->id, 'name' => 'Buswelu']);

        $teller = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'role_id' => $admin->company->roles()->where('key', 'teller')->value('id')]);
        $this->actingAs($teller);

        $this->getJson('/api/v1/regions')->assertOk()->assertExactJson(['data' => [['id' => $arusha->id, 'name' => 'Arusha'], ['id' => $mwanza->id, 'name' => 'Mwanza']]]);
        $this->getJson("/api/v1/districts?region_id={$mwanza->id}")->assertOk()
            ->assertExactJson(['data' => [['id' => $ilemela->id, 'name' => 'Ilemela', 'regionId' => $mwanza->id], ['id' => $nyamagana->id, 'name' => 'Nyamagana', 'regionId' => $mwanza->id]]]);
        $this->getJson('/api/v1/districts')->assertOk()->assertExactJson(['data' => []]);
        $this->getJson("/api/v1/wards?district_id={$nyamagana->id}")->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.1', ['id' => $pamba->id, 'name' => 'Pamba', 'districtId' => $nyamagana->id]);
        $this->getJson('/api/v1/wards')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_admin_imports_geography_csv_and_sees_counts(): void
    {
        $admin = $this->signInAdmin();

        $this->getJson('/api/v1/master-data/geography')->assertOk()->assertJsonPath('data.regions', 0)->assertJsonPath('data.wards', 0);

        $file = UploadedFile::fake()->createWithContent('tz.csv', "region,district,ward,street\nKigoma,Kakonko,Gwarama,\nKigoma,Kakonko,,\n");
        $this->post('/api/v1/master-data/geography/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.rejected.0', ['line' => 3, 'reason' => 'Ward is missing.'])
            ->assertJsonPath('data.counts', ['regions' => 1, 'districts' => 1, 'wards' => 1]);

        $this->getJson('/api/v1/master-data/geography')->assertOk()->assertJsonPath('data.perRegion.0', ['id' => Region::first()->id, 'name' => 'Kigoma', 'districts' => 1, 'wards' => 1]);

        $bad = UploadedFile::fake()->createWithContent('tz.csv', "mkoa,wilaya\nKigoma,Kakonko\n");
        $this->post('/api/v1/master-data/geography/import', ['file' => $bad], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'VALIDATION_FAILED')->assertJsonValidationErrors('file');
        $this->postJson('/api/v1/master-data/geography/import', [])->assertUnprocessable()->assertJsonValidationErrors(['file' => 'Choose the CSV file to import.']);

        $officer = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'role_id' => $admin->company->roles()->where('key', 'loan_officer')->value('id')]);
        $this->actingAs($officer)->postJson('/api/v1/master-data/geography/import', [])->assertForbidden();
    }
}

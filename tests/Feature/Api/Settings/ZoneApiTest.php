<?php

namespace Tests\Feature\Api\Settings;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZoneApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_zone_with_branches_updates_and_deletes_it(): void
    {
        $admin = $this->signInAdmin();
        $kakonko = Branch::factory()->create(['company_id' => $admin->company_id, 'name' => 'Kakonko']);

        $this->postJson('/api/v1/settings/zones', ['zone_name' => 'KAGERA ZONE', 'branch_ids' => [$admin->branch_id, $kakonko->id]])
            ->assertCreated()
            ->assertJsonPath('message', 'Zone Registered successfully')
            ->assertJsonCount(2, 'data.branches');

        $zone = Zone::firstWhere('name', 'KAGERA ZONE');
        $this->assertSame($zone->id, $kakonko->fresh()->zone_id);

        $this->putJson("/api/v1/settings/zones/{$zone->id}", ['zone_name' => 'KIGOMA ZONE', 'branch_ids' => [$kakonko->id]])
            ->assertOk()
            ->assertJsonPath('data.name', 'KIGOMA ZONE');
        $this->assertNull(Branch::find($admin->branch_id)->zone_id);

        $this->getJson('/api/v1/settings/zones')->assertOk()->assertJsonPath('data.0.branches.0.name', 'Kakonko');

        $this->deleteJson("/api/v1/settings/zones/{$zone->id}")->assertOk();
        $this->assertModelMissing($zone);
        $this->assertNull($kakonko->fresh()->zone_id);
    }

    public function test_zone_validation_and_manager_guard(): void
    {
        $admin = $this->signInAdmin();
        $zone = Zone::create(['company_id' => $admin->company_id, 'name' => 'NORTH']);
        $foreignBranch = Branch::factory()->create();

        $this->postJson('/api/v1/settings/zones', ['zone_name' => 'NORTH', 'branch_ids' => [$foreignBranch->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['zone_name', 'branch_ids.0']);

        Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'zone_id' => $zone->id, 'role_id' => $admin->company->roles()->where('key', 'zone_manager')->value('id')]);
        $this->deleteJson("/api/v1/settings/zones/{$zone->id}")->assertUnprocessable();
    }

    public function test_permission_and_company_isolation(): void
    {
        $admin = $this->signInAdmin();
        $foreignZone = Zone::create(['company_id' => Company::factory()->create()->id, 'name' => 'FOREIGN']);

        $this->getJson("/api/v1/settings/zones/{$foreignZone->id}")->assertNotFound();

        $teller = Employee::factory()->create([
            'company_id' => $admin->company_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->company->roles()->where('key', 'teller')->value('id'),
        ]);
        $this->actingAs($teller)->getJson('/api/v1/settings/zones')->assertForbidden();
    }
}

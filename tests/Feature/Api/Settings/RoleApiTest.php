<?php

namespace Tests\Feature\Api\Settings;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Zone;
use App\Services\AccessControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_list_with_scope_and_permission_catalogue(): void
    {
        $this->signInAdmin();

        $this->getJson('/api/v1/settings/roles')
            ->assertOk()
            ->assertJsonFragment(['key' => 'zone_manager', 'scope' => 'zone'])
            ->assertJsonFragment(['key' => 'super_admin', 'is_locked' => true]);

        $this->getJson('/api/v1/settings/permissions')->assertOk()->assertJsonFragment(['key' => 'capital.view', 'group' => 'capital']);
    }

    public function test_admin_toggles_role_permissions_but_super_admin_is_locked(): void
    {
        $admin = $this->signInAdmin();
        $teller = Role::where('company_id', $admin->company_id)->where('key', 'teller')->first();
        $employee = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'role_id' => $teller->id]);
        $this->assertFalse(app(AccessControl::class)->can($employee, 'reports.view'));

        $this->putJson("/api/v1/settings/roles/{$teller->id}/permissions", ['permissions' => ['dashboard.view', 'payments.cash', 'reports.view']])
            ->assertOk()
            ->assertJsonPath('message', 'Role Permissions Updated successfully');

        $employee->refresh()->load('role.permissions');
        $this->assertTrue(app(AccessControl::class)->can($employee, 'reports.view'));
        $this->assertFalse(app(AccessControl::class)->can($employee, 'customers.manage'));
        $this->assertTrue(AuditLog::where('action', 'Role.permissions_updated')->exists());

        $this->putJson("/api/v1/settings/roles/{$teller->id}/permissions", ['permissions' => ['not.a.permission']])
            ->assertUnprocessable()->assertJsonValidationErrors('permissions.0');

        $super = Role::where('company_id', $admin->company_id)->where('key', 'super_admin')->first();
        $this->putJson("/api/v1/settings/roles/{$super->id}/permissions", ['permissions' => []])->assertUnprocessable();
    }

    public function test_assign_role_and_zone_to_employee(): void
    {
        $admin = $this->signInAdmin();
        $zone = Zone::create(['company_id' => $admin->company_id, 'name' => 'NORTH']);
        $roles = Role::where('company_id', $admin->company_id)->pluck('id', 'key');
        $employee = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'role_id' => $roles['teller']]);

        $this->putJson("/api/v1/settings/employees/{$employee->id}/role", ['role_id' => $roles['zone_manager']])
            ->assertUnprocessable()->assertJsonValidationErrors('zone_id');

        $this->putJson("/api/v1/settings/employees/{$employee->id}/role", ['role_id' => $roles['zone_manager'], 'zone_id' => $zone->id])
            ->assertOk()->assertJsonPath('message', 'Staff Role Updated successfully');
        $this->assertSame($zone->id, $employee->fresh()->zone_id);

        $this->putJson("/api/v1/settings/employees/{$employee->id}/role", ['role_id' => $roles['loan_officer'], 'zone_id' => $zone->id])->assertOk();
        $this->assertNull($employee->fresh()->zone_id);

        $this->putJson("/api/v1/settings/employees/{$admin->id}/role", ['role_id' => $roles['teller']])->assertForbidden();

        $hrUser = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'role_id' => $roles['admin']]);
        $this->actingAs($hrUser)->putJson("/api/v1/settings/employees/{$employee->id}/role", ['role_id' => $roles['super_admin']])->assertForbidden();

        $teller = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'role_id' => $roles['teller']]);
        $this->actingAs($teller)->getJson('/api/v1/settings/roles')->assertForbidden();
    }
}

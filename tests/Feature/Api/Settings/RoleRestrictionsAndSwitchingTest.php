<?php

namespace Tests\Feature\Api\Settings;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Enhancement §12.3 (HR role restrictions) and §13 (multi-role, Switch Role).
 */
class RoleRestrictionsAndSwitchingTest extends TestCase
{
    use RefreshDatabase;

    private Employee $superAdmin;

    private Employee $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = $this->signInAdmin();
        $this->hr = $this->staff('hr');
    }

    public function test_roles_and_permissions_are_for_the_super_admin_only(): void
    {
        $role = $this->role('loan_officer');

        foreach ([$this->hr, $this->staff('admin')] as $employee) {
            $this->actingAs($employee)->getJson('/api/v1/settings/roles')->assertForbidden();
            $this->actingAs($employee)->getJson('/api/v1/settings/permissions')->assertForbidden();
            $this->actingAs($employee)->putJson("/api/v1/settings/roles/{$role->id}/permissions", ['permissions' => ['users.manage']])->assertForbidden();
        }

        $this->actingAs($this->superAdmin)->getJson('/api/v1/settings/roles')->assertOk();
        $this->assertFalse($role->permissions()->where('permission', 'users.manage')->exists());
    }

    public function test_hr_cannot_give_or_take_the_protected_roles_by_any_route(): void
    {
        $officer = $this->staff('loan_officer');
        $finance = $this->staff('finance');

        foreach (['hr', 'admin', 'super_admin', 'finance', 'credit_officer'] as $key) {
            $this->actingAs($this->hr)->putJson("/api/v1/settings/employees/{$officer->id}/role", ['role_id' => $this->role($key)->id])->assertForbidden();
            $this->actingAs($this->hr)->putJson("/api/v1/settings/employees/{$officer->id}/roles", ['role_ids' => [$this->role($key)->id]])->assertForbidden();
        }
        $this->actingAs($this->hr)->putJson("/api/v1/settings/employees/{$finance->id}/role", ['role_id' => $this->role('teller')->id])->assertForbidden();
        $this->assertSame($this->role('loan_officer')->id, (int) $officer->refresh()->role_id);
        $this->assertSame($this->role('finance')->id, (int) $finance->refresh()->role_id);

        $this->actingAs($this->hr)->putJson("/api/v1/settings/employees/{$officer->id}/role", ['role_id' => $this->role('teller')->id])->assertOk();
        $this->assertSame($this->role('teller')->id, (int) $officer->refresh()->role_id);
    }

    public function test_the_hrm_role_list_hides_the_protected_roles_from_hr(): void
    {
        $keys = fn (Employee $employee): array => collect($this->actingAs($employee)->getJson('/api/v1/hrm/options/roles')->assertOk()->json('data'))
            ->map(fn (array $row): string => Role::findOrFail($row['value'])->key)->all();

        $this->assertEmpty(array_intersect($keys($this->hr), ['hr', 'admin', 'super_admin', 'finance', 'credit_officer', 'shareholder']));
        $this->assertContains('loan_officer', $keys($this->hr));
        $this->assertContains('finance', $keys($this->staff('admin')));
    }

    public function test_hr_cannot_change_the_privileges_of_a_protected_employee(): void
    {
        $finance = $this->staff('finance');

        $this->actingAs($this->hr)->getJson("/api/v1/hrm/staff/{$finance->id}/privileges")->assertOk()->assertJsonPath('data.can_edit', false);
        $this->actingAs($this->hr)->putJson("/api/v1/hrm/staff/{$finance->id}/privileges", ['permissions' => ['dashboard.view']])->assertForbidden();
    }

    public function test_a_multi_role_employee_switches_role_without_signing_out(): void
    {
        $employee = $this->staff('finance');
        $this->actingAs($this->superAdmin)->putJson("/api/v1/settings/employees/{$employee->id}/roles", ['role_ids' => [$this->role('hr')->id]])->assertOk();
        $token = $employee->createToken('test')->plainTextToken;

        $me = $this->asToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $me->assertJsonPath('data.role.key', 'finance');
        $this->assertSame(['finance', 'hr'], collect($me->json('data.roles'))->pluck('key')->all());
        $this->asToken($token)->getJson('/api/v1/dashboard/hr')->assertForbidden();

        $this->asToken($token)->postJson('/api/v1/auth/switch-role', ['role_id' => $this->role('hr')->id])->assertOk()->assertJsonPath('data.role.key', 'hr');
        $this->asToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.role.key', 'hr');
        $this->asToken($token)->getJson('/api/v1/dashboard/hr')->assertOk();
        $this->asToken($token)->getJson('/api/v1/dashboard/finance')->assertForbidden();
        $this->assertSame($this->role('finance')->id, (int) $employee->refresh()->role_id, 'The stored role never changes.');
        $this->assertTrue(AuditLog::where('action', 'Employee.role_switched')->where('employee_id', $employee->id)->exists());

        $this->asToken($token)->postJson('/api/v1/auth/switch-role', ['role_id' => $this->role('finance')->id])->assertOk()->assertJsonPath('data.role.key', 'finance');
        $this->asToken($token)->getJson('/api/v1/dashboard/finance')->assertOk();
    }

    public function test_a_role_cannot_be_switched_to_unless_assigned_and_a_revoked_role_stops_applying(): void
    {
        $employee = $this->staff('finance');
        $employee->additionalRoles()->attach($this->role('hr')->id);
        $token = $employee->createToken('test')->plainTextToken;

        $this->asToken($token)->postJson('/api/v1/auth/switch-role', ['role_id' => $this->role('admin')->id])->assertForbidden();
        $this->asToken($token)->postJson('/api/v1/auth/switch-role', ['role_id' => $this->role('super_admin')->id])->assertForbidden();

        $this->asToken($token)->postJson('/api/v1/auth/switch-role', ['role_id' => $this->role('hr')->id])->assertOk();
        $employee->additionalRoles()->detach();

        $this->asToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.role.key', 'finance');
        $this->asToken($token)->getJson('/api/v1/dashboard/hr')->assertForbidden();
    }

    public function test_reports_follow_the_department_of_the_role(): void
    {
        $this->actingAs($this->hr)->getJson('/api/v1/reports/collection')->assertForbidden();
        $this->actingAs($this->hr)->getJson('/api/v1/reports/portfolio')->assertForbidden();

        $crm = $this->staff('crm');
        $this->actingAs($crm)->getJson('/api/v1/reports/collection')->assertOk();
        $this->actingAs($crm)->getJson('/api/v1/reports/cash')->assertForbidden();
        $this->actingAs($crm)->getJson('/api/v1/reports/financial/cash-flow')->assertForbidden();

        $credit = $this->staff('credit_officer');
        $this->actingAs($credit)->getJson('/api/v1/reports/portfolio')->assertOk();
        $this->actingAs($credit)->getJson('/api/v1/reports/daily')->assertForbidden();

        foreach (['admin', 'finance'] as $role) {
            $employee = $this->staff($role);
            foreach (['/api/v1/reports/cash', '/api/v1/reports/collection', '/api/v1/reports/portfolio', '/api/v1/reports/financial/cash-flow'] as $url) {
                $this->actingAs($employee)->getJson($url)->assertOk();
            }
        }
    }

    public function test_hrm_permissions_apply_only_to_hr_admin_and_super_admin(): void
    {
        $teller = $this->staff('teller');
        $teller->permissionOverrides()->create(['permission' => 'hrm.manage', 'granted' => true]);

        $this->actingAs($teller)->getJson('/api/v1/hrm/staff')->assertForbidden();
        $this->actingAs($this->hr)->getJson('/api/v1/hrm/staff')->assertOk();
    }

    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function role(string $key): Role
    {
        return Role::where('company_id', $this->superAdmin->company_id)->where('key', $key)->firstOrFail();
    }

    private function staff(string $role): Employee
    {
        return Employee::factory()->create([
            'company_id' => $this->superAdmin->company_id,
            'branch_id' => $this->superAdmin->branch_id,
            'role_id' => $this->role($role)->id,
        ]);
    }
}

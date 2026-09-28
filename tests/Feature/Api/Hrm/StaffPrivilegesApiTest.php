<?php

namespace Tests\Feature\Api\Hrm;

use App\Models\Employee;
use App\Models\EmployeePermission;
use App\Services\AccessControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StaffPrivilegesApiTest extends TestCase
{
    use RefreshDatabase;

    private function staff(Employee $admin, string $role, array $attributes = []): Employee
    {
        return Employee::factory()->create($attributes + [
            'company_id' => $admin->company_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->company->roles()->where('key', $role)->value('id'),
        ]);
    }

    /**
     * @return list<string>
     */
    private function rolePermissions(string $role): array
    {
        return config("permissions.roles.{$role}.permissions");
    }

    /**
     * @param  list<string>  $permissions
     */
    private function save(Employee $employee, array $permissions): TestResponse
    {
        return $this->putJson("/api/v1/hrm/staff/{$employee->id}/privileges", ['permissions' => $permissions]);
    }

    private function actAs(Employee $employee): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($employee->fresh());
    }

    public function test_shows_the_employee_and_effective_permissions(): void
    {
        $admin = $this->signInAdmin();
        $teller = $this->staff($admin, 'teller', ['employee_number' => 'MK-0072026', 'username' => 'kassim']);

        $this->getJson("/api/v1/hrm/staff/{$teller->id}/privileges")
            ->assertOk()
            ->assertJsonPath('data.employee.id', $teller->id)
            ->assertJsonPath('data.employee.employee_number', 'MK-0072026')
            ->assertJsonPath('data.employee.full_name', $teller->full_name)
            ->assertJsonPath('data.employee.username', 'kassim')
            ->assertJsonPath('data.employee.phone', $teller->phone)
            ->assertJsonPath('data.employee.branch', $admin->branch->name)
            ->assertJsonPath('data.employee.role.key', 'teller')
            ->assertJsonPath('data.permissions', array_values(array_intersect(array_keys(config('permissions.permissions')), $this->rolePermissions('teller'))))
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.granted', [])
            ->assertJsonCount(count(config('permissions.permissions')), 'data.catalogue')
            ->assertJsonFragment(['key' => 'hrm.staff_privileges', 'group' => 'hrm']);
    }

    public function test_grant_and_revoke_persist_and_change_real_authorization(): void
    {
        $admin = $this->signInAdmin();
        $hr = $this->staff($admin, 'hr');
        $teller = $this->staff($admin, 'teller');

        $this->actAs($hr);
        $this->getJson('/api/v1/settings/roles')->assertOk();
        $this->actAs($teller);
        $this->getJson('/api/v1/settings/roles')->assertForbidden();

        $this->actAs($admin);
        $this->save($hr, array_values(array_diff($this->rolePermissions('hr'), ['users.manage'])))
            ->assertOk()->assertJsonPath('message', 'Staff Privileges Updated successfully')->assertJsonPath('data.revoked', ['users.manage']);
        $this->save($teller, [...$this->rolePermissions('teller'), 'users.manage'])
            ->assertOk()->assertJsonPath('data.granted', ['users.manage']);

        $this->assertDatabaseHas('employee_permissions', ['employee_id' => $hr->id, 'permission' => 'users.manage', 'granted' => false]);
        $this->assertDatabaseHas('employee_permissions', ['employee_id' => $teller->id, 'permission' => 'users.manage', 'granted' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Employee.privileges_updated', 'auditable_id' => $teller->id, 'employee_id' => $admin->id]);

        $this->actAs($hr);
        $this->getJson('/api/v1/settings/roles')->assertForbidden();
        $this->actAs($teller);
        $this->getJson('/api/v1/settings/roles')->assertOk();

        $this->actAs($admin);
        $this->getJson("/api/v1/hrm/staff/{$teller->id}/privileges")->assertOk()->assertJsonFragment(['granted' => ['users.manage']]);

        // Restoring the role set removes the overrides again.
        $this->save($teller, $this->rolePermissions('teller'))->assertOk();
        $this->assertDatabaseMissing('employee_permissions', ['employee_id' => $teller->id]);
        $this->assertFalse(app(AccessControl::class)->can($teller->fresh(), 'users.manage'));
    }

    public function test_privileges_survive_logout_and_login_with_a_new_token(): void
    {
        $admin = $this->signInAdmin();
        $teller = $this->staff($admin, 'teller');

        $this->save($teller, [...array_diff($this->rolePermissions('teller'), ['payments.cash']), 'reports.view'])->assertOk();

        $this->app['auth']->forgetGuards();
        $first = $this->postJson('/api/v1/auth/login', ['phone' => $teller->phone, 'password' => 'password'])->assertOk();
        $this->assertContains('reports.view', $first->json('user.permissions'));
        $this->assertNotContains('payments.cash', $first->json('user.permissions'));

        $this->app['auth']->forgetGuards();
        $this->withToken($first->json('token'))->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, $teller->tokens()->count());

        $this->app['auth']->forgetGuards();
        $second = $this->withoutToken()->postJson('/api/v1/auth/login', ['phone' => $teller->phone, 'password' => 'password'])->assertOk();
        $this->app['auth']->forgetGuards();
        $me = $this->withToken($second->json('token'))->getJson('/api/v1/auth/me')->assertOk();
        $this->assertContains('reports.view', $me->json('data.permissions'));
        $this->assertNotContains('payments.cash', $me->json('data.permissions'));

        $this->assertSame(
            ['payments.cash' => 0, 'reports.view' => 1],
            DB::table('employee_permissions')->where('employee_id', $teller->id)->orderBy('permission')->pluck('granted', 'permission')->map(fn ($granted): int => (int) $granted)->all(),
        );
    }

    public function test_unauthorized_staff_get_403_and_view_only_roles_see_read_only(): void
    {
        $admin = $this->signInAdmin();
        $teller = $this->staff($admin, 'teller');
        $loanOfficer = $this->staff($admin, 'loan_officer');

        $this->actAs($teller);
        $this->getJson("/api/v1/hrm/staff/{$loanOfficer->id}/privileges")->assertForbidden();
        $this->save($loanOfficer, ['reports.view'])->assertForbidden();
        $this->assertDatabaseMissing('employee_permissions', ['employee_id' => $loanOfficer->id]);

        // A custom role that manages staff records but not privileges sees the page read-only.
        $viewer = $this->staff($admin, 'teller');
        $this->actAs($admin);
        $this->save($viewer, [...$this->rolePermissions('teller'), 'hrm.manage'])->assertOk();
        $this->actAs($viewer);
        $this->getJson("/api/v1/hrm/staff/{$loanOfficer->id}/privileges")->assertOk()->assertJsonPath('data.can_edit', false);
        $this->save($loanOfficer, ['reports.view'])->assertForbidden();
    }

    public function test_rejects_unknown_keys_and_keys_the_actor_does_not_hold(): void
    {
        $superAdmin = $this->signInAdmin();
        $hr = $this->staff($superAdmin, 'hr');
        $teller = $this->staff($superAdmin, 'teller');

        $this->save($teller, [...$this->rolePermissions('teller'), 'loans.fly'])->assertUnprocessable()->assertJsonValidationErrors('permissions.'.count($this->rolePermissions('teller')));

        $this->actAs($hr);
        $this->save($teller, [...$this->rolePermissions('teller'), 'capital.manage'])
            ->assertForbidden()->assertJsonPath('message', 'You cannot grant or revoke privileges you do not hold: capital.manage.');
        $this->save($teller, array_values(array_diff($this->rolePermissions('teller'), ['payments.cash'])))->assertForbidden();
        $this->save($teller, [...$this->rolePermissions('teller'), 'reports.view'])->assertOk();

        $this->assertDatabaseMissing('employee_permissions', ['employee_id' => $teller->id, 'permission' => 'capital.manage']);
        $this->assertDatabaseHas('employee_permissions', ['employee_id' => $teller->id, 'permission' => 'reports.view', 'granted' => true]);
    }

    public function test_cannot_edit_own_or_super_admin_privileges_and_super_admin_keeps_full_access(): void
    {
        $superAdmin = $this->signInAdmin();
        $admin = $this->staff($superAdmin, 'admin');

        $this->save($superAdmin, [])->assertForbidden();

        $this->actAs($admin);
        $this->save($admin, [...$this->rolePermissions('admin'), 'capital.view'])->assertForbidden()->assertJsonPath('message', 'You cannot change your own privileges.');
        $this->getJson("/api/v1/hrm/staff/{$admin->id}/privileges")->assertOk()->assertJsonPath('data.can_edit', false);
        $this->save($superAdmin->fresh(), ['dashboard.view'])->assertUnprocessable();

        $this->assertSame(app(AccessControl::class)->implicitPermissions(), app(AccessControl::class)->permissionsFor($superAdmin->fresh()));
        $this->assertNotContains('approvals.self_approve', app(AccessControl::class)->permissionsFor($superAdmin->fresh()), 'rule 6: self-approval is never implied');
    }

    public function test_staff_of_another_company_are_not_found(): void
    {
        $admin = $this->signInAdmin();
        $teller = $this->staff($admin, 'teller');

        $this->signInAdmin();
        $this->getJson("/api/v1/hrm/staff/{$teller->id}/privileges")->assertNotFound();
        $this->save($teller, [...$this->rolePermissions('teller'), 'reports.view'])->assertNotFound();
        $this->assertDatabaseMissing('employee_permissions', ['employee_id' => $teller->id]);
    }

    /**
     * @return list<string>
     */
    private function itemPermissions(string $item): array
    {
        foreach (config('permissions.privileges') as $group) {
            foreach ($group['items'] as $definition) {
                if ($definition['key'] === $item) {
                    return $definition['permissions'];
                }
            }
        }

        $this->fail("Unknown privilege item {$item}.");
    }

    public function test_privilege_structure_mirrors_the_live_list_and_covers_every_permission_once(): void
    {
        $groups = config('permissions.privileges');
        $keys = array_keys(config('permissions.permissions'));

        // Live admin/privillage/{id}: 16 items less CLIENTLESS (Agent module removed 2026-09-17) = 15 in the displayed (alphabetical) order with the live item keys.
        $this->assertSame(
            ['apply', 'aprove', 'bank', 'bankpassword', 'customer', 'debit', 'expenses', 'float', 'group', 'income', 'loan', 'penarty', 'report', 'saving', 'teller'],
            array_column($groups[0]['items'], 'key'),
        );
        $this->assertSame(
            ['APPLY LOAN', 'APPROVE', 'BANK', 'BANK PASSWORD', 'CUSTOMER', 'DEBIT PENDING', 'EXPENSES', 'FLOAT', 'GROUP', 'INCOME', 'LOAN', 'PENALTY', 'REPORTS', 'SAVING', 'TELLER'],
            array_column($groups[0]['items'], 'label'),
        );

        $mapped = [];
        $itemKeys = [];
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $this->assertNotEmpty($item['permissions'], "{$item['key']} controls no permission");
                $this->assertSame([], array_diff($item['permissions'], $keys), "{$item['key']} maps to unknown permissions");
                $mapped = [...$mapped, ...$item['permissions']];
                $itemKeys[] = $item['key'];
            }
        }
        $this->assertSame(count($itemKeys), count(array_unique($itemKeys)));
        $this->assertEqualsCanonicalizing($keys, $mapped, 'Every permission must belong to exactly one privilege item.');

        $admin = $this->signInAdmin();
        $teller = $this->staff($admin, 'teller');
        $this->getJson("/api/v1/hrm/staff/{$teller->id}/privileges")
            ->assertOk()
            ->assertJsonPath('data.privilege_groups.0.label', 'Privilege List')
            ->assertJsonPath('data.privilege_groups.0.items.8', ['key' => 'group', 'label' => 'GROUP', 'permissions' => ['groups.view', 'groups.manage']])
            ->assertJsonCount(count($groups), 'data.privilege_groups');
    }

    public function test_adding_and_removing_a_live_item_changes_api_access(): void
    {
        $admin = $this->signInAdmin();
        $teller = $this->staff($admin, 'teller');

        $this->actAs($teller);
        $this->getJson('/api/v1/groups')->assertForbidden();
        $this->getJson('/api/v1/loans')->assertOk();

        // Add GROUP (both keys), remove LOAN (a role-provided permission).
        $this->actAs($admin);
        $withGroup = array_values(array_diff([...$this->rolePermissions('teller'), ...$this->itemPermissions('group')], $this->itemPermissions('loan')));
        $saved = $this->save($teller, $withGroup)->assertOk()->assertJsonPath('data.revoked', ['loans.view']);
        $this->assertEqualsCanonicalizing(['groups.view', 'groups.manage'], $saved->json('data.granted'));

        $this->actAs($teller);
        $this->getJson('/api/v1/groups')->assertOk();
        $this->postJson('/api/v1/groups', ['group_name' => 'Wanawake'])->assertCreated();
        $this->getJson('/api/v1/loans')->assertForbidden();
        $this->assertNotContains('loans.view', $this->getJson('/api/v1/auth/me')->json('data.permissions'));

        // Remove GROUP again: access is gone.
        $this->actAs($admin);
        $this->save($teller, array_values(array_diff($withGroup, $this->itemPermissions('group'))))->assertOk();
        $this->actAs($teller);
        $this->getJson('/api/v1/groups')->assertForbidden();
        $this->postJson('/api/v1/groups', ['group_name' => 'Vijana'])->assertForbidden();
    }

    public function test_changing_the_role_keeps_the_employee_overrides(): void
    {
        $admin = $this->signInAdmin();
        $teller = $this->staff($admin, 'teller');

        $this->save($teller, [...array_diff($this->rolePermissions('teller'), ['payments.cash']), 'income.view'])->assertOk();

        $loanOfficerRole = $admin->company->roles()->where('key', 'loan_officer')->value('id');
        $this->putJson("/api/v1/settings/employees/{$teller->id}/role", ['role_id' => $loanOfficerRole])->assertOk();

        $this->assertDatabaseHas('employee_permissions', ['employee_id' => $teller->id, 'permission' => 'income.view', 'granted' => true]);
        $this->assertDatabaseHas('employee_permissions', ['employee_id' => $teller->id, 'permission' => 'payments.cash', 'granted' => false]);

        $page = $this->getJson("/api/v1/hrm/staff/{$teller->id}/privileges")->assertOk()->assertJsonPath('data.employee.role.key', 'loan_officer');
        $this->assertEqualsCanonicalizing($this->rolePermissions('loan_officer'), $page->json('data.role_permissions'));
        $this->assertEqualsCanonicalizing([...$this->rolePermissions('loan_officer'), 'income.view'], $page->json('data.permissions'));

        $this->actAs($teller);
        $this->getJson('/api/v1/loan-fees/income')->assertOk();
    }

    public function test_invalid_keys_are_rejected_before_anything_is_written(): void
    {
        $admin = $this->signInAdmin();
        $teller = $this->staff($admin, 'teller');
        $this->save($teller, [...$this->rolePermissions('teller'), 'income.view'])->assertOk();

        $this->save($teller, ['reports.view', 'group', 'dashboard.view'])->assertUnprocessable()->assertJsonValidationErrors('permissions.1');
        $this->save($teller, ['reports.view', 42])->assertUnprocessable();
        $this->save($teller, ['reports.view', 'reports.view'])->assertUnprocessable();
        $this->putJson("/api/v1/hrm/staff/{$teller->id}/privileges", [])->assertUnprocessable()->assertJsonValidationErrors('permissions');

        $this->assertSame(
            ['income.view' => 1],
            DB::table('employee_permissions')->where('employee_id', $teller->id)->pluck('granted', 'permission')->map(fn ($granted): int => (int) $granted)->all(),
        );
    }

    public function test_a_failure_during_the_save_rolls_back_the_whole_override_set(): void
    {
        $admin = $this->signInAdmin();
        $teller = $this->staff($admin, 'teller');
        $this->save($teller, [...$this->rolePermissions('teller'), 'income.view'])->assertOk();

        EmployeePermission::creating(function (EmployeePermission $row): void {
            if ($row->permission === 'reports.view') {
                throw new \RuntimeException('Simulated database failure');
            }
        });

        // Would delete the income.view grant, revoke payments.cash, grant loans.apply, then fail on reports.view.
        $this->save($teller, [...array_diff($this->rolePermissions('teller'), ['payments.cash']), 'loans.apply', 'reports.view'])->assertServerError();

        $this->assertSame(
            ['income.view' => 1],
            DB::table('employee_permissions')->where('employee_id', $teller->id)->pluck('granted', 'permission')->map(fn ($granted): int => (int) $granted)->all(),
        );
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'Employee.privileges_updated')->where('auditable_id', $teller->id)->count());
        $this->assertTrue(app(AccessControl::class)->can($teller->fresh(), 'payments.cash'));
    }
}

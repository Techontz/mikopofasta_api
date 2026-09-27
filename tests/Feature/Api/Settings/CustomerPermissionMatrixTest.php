<?php

namespace Tests\Feature\Api\Settings;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Services\AccessControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CUSTOMER_MODULE_IMPLEMENTATION.md §8.9 — the adapted customer role matrix.
 */
class CustomerPermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private const KEYS = ['customers.view', 'customers.manage', 'customers.approve', 'customers.assign_officer', 'branches.view_all'];

    /**
     * @var array<string, list<string>>
     */
    private const MATRIX = [
        'super_admin' => ['customers.view', 'customers.manage', 'customers.approve', 'customers.assign_officer', 'branches.view_all'],
        'admin' => ['customers.view', 'customers.manage', 'customers.approve', 'customers.assign_officer', 'branches.view_all'],
        'branch_manager' => ['customers.view', 'customers.manage', 'customers.approve', 'customers.assign_officer'],
        'loan_officer' => ['customers.view', 'customers.manage'],
        'finance' => ['customers.view', 'branches.view_all'],
        'credit_officer' => ['customers.view', 'branches.view_all'],
        'zone_manager' => ['customers.view'],
        'hr' => ['branches.view_all'],
        'teller' => [],
        'shareholder' => [],
    ];

    public function test_seeded_roles_follow_the_customer_matrix_exactly(): void
    {
        $admin = $this->signInAdmin();
        $access = app(AccessControl::class);

        $this->assertEqualsCanonicalizing(array_keys(self::MATRIX), array_keys(config('permissions.roles')));
        foreach (['customers.register', 'customers.update', 'customers.categorize'] as $removed) {
            $this->assertArrayNotHasKey($removed, config('permissions.permissions'));
        }

        foreach (self::MATRIX as $role => $expected) {
            $employee = Employee::factory()->create([
                'company_id' => $admin->company_id, 'branch_id' => $admin->branch_id,
                'role_id' => Role::where('company_id', $admin->company_id)->where('key', $role)->value('id'),
            ]);
            $granted = array_values(array_intersect(self::KEYS, $access->permissionsFor($employee)));

            $this->assertSame($expected, $granted, "Customer permissions of {$role}");
            $this->assertSame(in_array('branches.view_all', $expected, true), $employee->role->scope === 'company', "{$role}: branches.view_all follows the company data scope");
        }

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonFragment(['customers.assign_officer']);
    }

    public function test_data_migration_rewrites_existing_system_role_permissions(): void
    {
        $company = Company::factory()->create();
        $roles = [];
        foreach (array_keys(self::MATRIX) as $key) {
            $roles[$key] = Role::create(['company_id' => $company->id, 'key' => $key, 'name' => $key, 'is_system' => true])->id;
        }
        $custom = Role::create(['company_id' => $company->id, 'key' => 'custom', 'name' => 'Custom', 'is_system' => false])->id;

        $old = [
            'teller' => ['dashboard.view', 'customers.view', 'customers.register'],
            'loan_officer' => ['customers.view', 'customers.register', 'customers.update', 'customers.categorize', 'loans.apply'],
            'admin' => ['customers.view', 'customers.update'],
            'hr' => ['users.manage'],
        ];
        foreach ($old as $key => $permissions) {
            foreach ($permissions as $permission) {
                DB::table('role_permissions')->insert(['role_id' => $roles[$key], 'permission' => $permission]);
            }
        }
        DB::table('role_permissions')->insert(['role_id' => $custom, 'permission' => 'customers.update']);

        $migration = require database_path('migrations/2026_09_13_154257_update_role_permissions_for_customer_module.php');
        $migration->up();
        $migration->up();

        $permissions = fn (int $roleId): array => DB::table('role_permissions')->where('role_id', $roleId)->orderBy('permission')->pluck('permission')->all();

        $this->assertSame(['dashboard.view'], $permissions($roles['teller']));
        $this->assertSame(['customers.manage', 'customers.view', 'loans.apply'], $permissions($roles['loan_officer']));
        $this->assertSame(['branches.view_all', 'customers.approve', 'customers.assign_officer', 'customers.manage', 'customers.view'], $permissions($roles['admin']));
        $this->assertSame(['branches.view_all', 'users.manage'], $permissions($roles['hr']));
        $this->assertSame(['customers.view'], $permissions($roles['zone_manager']));
        $this->assertSame([], $permissions($custom));
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Customer permissions replaced by the Customer Module (CUSTOMER_MODULE_SPEC.md §9).
     *
     * @var list<string>
     */
    private const REMOVED = ['customers.register', 'customers.update', 'customers.categorize'];

    /**
     * Keys this migration manages on system roles.
     *
     * @var list<string>
     */
    private const MANAGED = ['customers.view', 'customers.manage', 'customers.approve', 'customers.assign_officer', 'branches.view_all'];

    /**
     * Adapted role matrix for the system roles.
     *
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
    ];

    /**
     * Previous customer permissions of the system roles, restored on rollback.
     *
     * @var array<string, list<string>>
     */
    private const PREVIOUS = [
        'super_admin' => ['customers.view', 'customers.register', 'customers.update', 'customers.categorize'],
        'admin' => ['customers.view', 'customers.update'],
        'branch_manager' => ['customers.view', 'customers.register', 'customers.update', 'customers.categorize'],
        'loan_officer' => ['customers.view', 'customers.register', 'customers.update', 'customers.categorize'],
        'finance' => ['customers.view'],
        'credit_officer' => ['customers.view'],
        'zone_manager' => ['customers.view'],
        'hr' => [],
        'teller' => ['customers.view', 'customers.register'],
    ];

    /**
     * Remove the old customer keys everywhere and apply the new matrix to existing system roles.
     */
    public function up(): void
    {
        DB::table('role_permissions')->whereIn('permission', self::REMOVED)->delete();

        $this->applyMatrix(self::MATRIX, self::MANAGED);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', ['customers.manage', 'customers.approve', 'customers.assign_officer', 'branches.view_all'])->delete();

        $this->applyMatrix(self::PREVIOUS, ['customers.view', ...self::REMOVED]);
    }

    /**
     * @param  array<string, list<string>>  $matrix
     * @param  list<string>  $managed
     */
    private function applyMatrix(array $matrix, array $managed): void
    {
        $roles = DB::table('roles')->where('is_system', true)->whereIn('key', array_keys($matrix))->get(['id', 'key']);

        foreach ($roles as $role) {
            DB::table('role_permissions')->where('role_id', $role->id)->whereIn('permission', $managed)->delete();
            DB::table('role_permissions')->insert(array_map(
                fn (string $permission): array => ['role_id' => $role->id, 'permission' => $permission],
                $matrix[$role->key],
            ));
        }
    }
};

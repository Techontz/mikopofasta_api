<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Staff privileges follow the existing staff-account permission: every role that already holds `users.manage`
     * (system Admin and HR, plus any custom role granted it) and the Super Admin receive `hrm.staff_privileges`.
     * Resetting a staff password to the configured default is limited to the administrator roles (Super Admin, Admin).
     */
    public function up(): void
    {
        $superAdminRoleIds = DB::table('roles')->where('is_system', true)->where('key', 'super_admin')->pluck('id');
        $userManagerRoleIds = DB::table('role_permissions')->where('permission', 'users.manage')->pluck('role_id');
        $adminRoleIds = DB::table('roles')->where('is_system', true)->where('key', 'admin')->pluck('id');

        $this->grant('hrm.staff_privileges', $superAdminRoleIds->merge($userManagerRoleIds)->unique()->all());
        $this->grant('hrm.staff_reset_password', $superAdminRoleIds->merge($adminRoleIds)->unique()->all());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', ['hrm.staff_privileges', 'hrm.staff_reset_password'])->delete();
    }

    /**
     * @param  list<int>  $roleIds
     */
    private function grant(string $permission, array $roleIds): void
    {
        DB::table('role_permissions')->whereIn('role_id', $roleIds)->where('permission', $permission)->delete();
        DB::table('role_permissions')->insert(array_map(
            fn (int $roleId): array => ['role_id' => $roleId, 'permission' => $permission],
            array_values($roleIds),
        ));
    }
};

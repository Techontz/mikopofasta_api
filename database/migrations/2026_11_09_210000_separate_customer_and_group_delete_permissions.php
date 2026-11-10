<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deleting customers and groups becomes its own permission, held by Admin; Super Admin holds every permission already.
 * Other roles that could delete through customers.manage / groups.manage no longer can.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('roles')->where('is_system', true)->where('key', 'admin')->pluck('id') as $roleId) {
            foreach (['customers.delete', 'groups.delete'] as $permission) {
                DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission' => $permission]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', ['customers.delete', 'groups.delete'])->delete();
    }
};

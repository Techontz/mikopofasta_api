<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pending Approvals / Applications on every dashboard (enhancement §11.2): HR reads its own workflows (staff loans and
 * salary advances, payroll, allowances); each workflow stays gated by its module permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('roles')->where('is_system', true)->where('key', 'hr')->pluck('id') as $roleId) {
            DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission' => 'approvals.view']);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->whereIn('role_id', DB::table('roles')->where('is_system', true)->where('key', 'hr')->select('id'))
            ->where('permission', 'approvals.view')
            ->delete();
    }
};

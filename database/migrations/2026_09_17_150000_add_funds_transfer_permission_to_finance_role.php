<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `funds.transfer` is Finance's own leg of the HQ money chain: Send Petty Cash To Branch and Send Reserve To
     * Investment Reserve. It is explicit-only (never implied by the Super Admin role), so the stored Finance role must
     * carry it; any other system role that picked it up while it was an Admin default loses it again — the owners
     * approve those requests from Pending Approvals, they never raise them.
     * Idempotent: inserted only where missing; custom (non-system) roles a company granted it to are left alone.
     */
    private const KEY = 'funds.transfer';

    public function up(): void
    {
        $finance = DB::table('roles')->where('is_system', true)->where('key', 'finance')->pluck('id');

        foreach ($finance as $roleId) {
            $exists = DB::table('role_permissions')->where('role_id', $roleId)->where('permission', self::KEY)->exists();
            if (! $exists) {
                DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission' => self::KEY]);
            }
        }

        $others = DB::table('roles')->where('is_system', true)->where('key', '!=', 'finance')->pluck('id');
        DB::table('role_permissions')->whereIn('role_id', $others)->where('permission', self::KEY)->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $roles = DB::table('roles')->where('is_system', true)->pluck('id');
        DB::table('role_permissions')->whereIn('role_id', $roles)->where('permission', self::KEY)->delete();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * C6 Pending Approvals list. Granted to the stored system roles Super Admin, Admin and Finance; other roles can be granted
     * it in Settings → Roles & Permissions. It does NOT grant `approvals.self_approve` (explicit only, never granted here).
     * Idempotent: inserted only where missing; down removes only this key.
     */
    private const KEY = 'approvals.view';

    /**
     * @var list<string>
     */
    private const ROLES = ['super_admin', 'admin', 'finance'];

    public function up(): void
    {
        $roles = DB::table('roles')->where('is_system', true)->whereIn('key', self::ROLES)->pluck('id');

        foreach ($roles as $roleId) {
            $exists = DB::table('role_permissions')->where('role_id', $roleId)->where('permission', self::KEY)->exists();
            if (! $exists) {
                DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission' => self::KEY]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('role_permissions')->where('permission', self::KEY)->delete();
    }
};

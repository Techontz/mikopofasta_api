<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Recording money recovered on a written-off loan (rule 8). Granted by default to the roles that receive money
     * (Super Admin, Admin, Finance, Teller); other roles can be granted it in Settings → Roles & Permissions. Idempotent:
     * inserted only where missing; down removes only this key.
     */
    private const KEY = 'loans.recover';

    /**
     * @var list<string>
     */
    private const ROLES = ['super_admin', 'admin', 'finance', 'teller'];

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

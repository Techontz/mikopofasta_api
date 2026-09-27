<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Shares Management permissions.
     *
     * @var list<string>
     */
    private const KEYS = ['shares.view', 'shares.issue', 'shares.transfer', 'shares.value', 'shares.manage'];

    /**
     * Share ownership, valuation and the shareholder register follow the capital permission model: only the Super Admin
     * holds capital permissions by default, so only the Super Admin receives the share permissions. Other roles can be
     * granted them in Settings → Roles & Permissions.
     *
     * @var array<string, list<string>>
     */
    private const MATRIX = [
        'super_admin' => self::KEYS,
    ];

    public function up(): void
    {
        $roles = DB::table('roles')->where('is_system', true)->whereIn('key', array_keys(self::MATRIX))->get(['id', 'key']);

        foreach ($roles as $role) {
            DB::table('role_permissions')->where('role_id', $role->id)->whereIn('permission', self::KEYS)->delete();
            DB::table('role_permissions')->insert(array_map(
                fn (string $permission): array => ['role_id' => $role->id, 'permission' => $permission],
                self::MATRIX[$role->key],
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', self::KEYS)->delete();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Loan money reversal permissions.
     *
     * @var list<string>
     */
    private const KEYS = ['loans.reverse_repayment', 'loans.reverse_disbursement'];

    /**
     * Reversing a repayment or a disbursement undoes posted money movements, so only the company-level finance roles
     * receive it by default (Super Admin already holds every permission through "*"). Other roles can be granted it in
     * Settings → Roles & Permissions.
     *
     * @var array<string, list<string>>
     */
    private const MATRIX = [
        'super_admin' => self::KEYS,
        'admin' => self::KEYS,
        'finance' => self::KEYS,
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

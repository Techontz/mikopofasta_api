<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Reversal maker/checker permissions (user ruling 2026-09-17): Finance requests the reversal of a direct penalty payment
     * (repayment and disbursement reversals keep loans.reverse_repayment / loans.reverse_disbursement, which now raise a
     * request); another Finance user, an Admin or the Super Admin approves. Super Admin already holds both through "*".
     *
     * @var array<string, list<string>>
     */
    private const MATRIX = [
        'super_admin' => ['penalties.reverse_payment', 'reversals.approve'],
        'admin' => ['reversals.approve'],
        'finance' => ['penalties.reverse_payment', 'reversals.approve'],
    ];

    public function up(): void
    {
        $roles = DB::table('roles')->where('is_system', true)->whereIn('key', array_keys(self::MATRIX))->get(['id', 'key']);

        foreach ($roles as $role) {
            foreach (self::MATRIX[$role->key] as $permission) {
                DB::table('role_permissions')->updateOrInsert(['role_id' => $role->id, 'permission' => $permission]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', ['penalties.reverse_payment', 'reversals.approve'])->delete();
    }
};

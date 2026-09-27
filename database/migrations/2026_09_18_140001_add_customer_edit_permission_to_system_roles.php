<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * customers.edit (user ruling 2026-09-18): every staff role that can open a customer profile may correct that
     * customer's details, not only the roles that register customers. Super Admin already holds it through "*";
     * HR and Teller cannot view customers, so they get nothing.
     *
     * @var list<string>
     */
    private const ROLES = ['admin', 'finance', 'zone_manager', 'branch_manager', 'credit_officer', 'loan_officer'];

    public function up(): void
    {
        $roles = DB::table('roles')->where('is_system', true)->whereIn('key', self::ROLES)->pluck('id');

        foreach ($roles as $roleId) {
            DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission' => 'customers.edit']);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', 'customers.edit')->delete();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Report access by role (enhancement §15): `reports.view` now means every non-financial report (Admin, Finance); the other
 * system roles get only the report families of their department. HR loses loan reports. Custom roles keep what they had.
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private const FAMILIES = [
        'hr' => [],
        'zone_manager' => ['reports.operations', 'reports.loans', 'reports.customers', 'reports.portfolio'],
        'branch_manager' => ['reports.operations', 'reports.loans', 'reports.customers', 'reports.portfolio'],
        'crm' => ['reports.customers', 'reports.loans'],
        'credit_officer' => ['reports.loans', 'reports.customers', 'reports.portfolio'],
    ];

    public function up(): void
    {
        foreach (self::FAMILIES as $key => $families) {
            foreach (DB::table('roles')->where('is_system', true)->where('key', $key)->pluck('id') as $roleId) {
                if (! DB::table('role_permissions')->where('role_id', $roleId)->where('permission', 'reports.view')->exists()) {
                    continue;
                }
                DB::table('role_permissions')->where('role_id', $roleId)->where('permission', 'reports.view')->delete();
                foreach ($families as $permission) {
                    DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission' => $permission]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::FAMILIES) as $key) {
            foreach (DB::table('roles')->where('is_system', true)->where('key', $key)->pluck('id') as $roleId) {
                DB::table('role_permissions')->where('role_id', $roleId)->whereIn('permission', ['reports.operations', 'reports.loans', 'reports.customers', 'reports.portfolio'])->delete();
                DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission' => 'reports.view']);
            }
        }
    }
};

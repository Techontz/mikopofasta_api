<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The live privilege items GROUP and INCOME get their own permissions. Until now customer groups were gated by the
     * customer permissions and the deducted loan fee income by `reports.financial`; to keep everyone's current access,
     * each new key is given to every role (system and custom) that holds its source key, and per-employee overrides of
     * the source key are copied (a grant stays a grant, a revocation stays a revocation).
     *
     * @var array<string, string> new key => source key
     */
    private const DERIVED = [
        'groups.view' => 'customers.view',
        'groups.manage' => 'customers.manage',
        'income.view' => 'reports.financial',
    ];

    public function up(): void
    {
        foreach (self::DERIVED as $permission => $source) {
            $roleIds = DB::table('role_permissions')->where('permission', $source)->pluck('role_id')->unique()->values();
            DB::table('role_permissions')->where('permission', $permission)->whereIn('role_id', $roleIds)->delete();
            DB::table('role_permissions')->insert($roleIds->map(fn (int $roleId): array => ['role_id' => $roleId, 'permission' => $permission])->all());

            $overrides = DB::table('employee_permissions')->where('permission', $source)->get(['employee_id', 'granted']);
            DB::table('employee_permissions')->where('permission', $permission)->whereIn('employee_id', $overrides->pluck('employee_id'))->delete();
            DB::table('employee_permissions')->insert($overrides->map(fn (object $row): array => [
                'employee_id' => $row->employee_id,
                'permission' => $permission,
                'granted' => $row->granted,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', array_keys(self::DERIVED))->delete();
        DB::table('employee_permissions')->whereIn('permission', array_keys(self::DERIVED))->delete();
    }
};

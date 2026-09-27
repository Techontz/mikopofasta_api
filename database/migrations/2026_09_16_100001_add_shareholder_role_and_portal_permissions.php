<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Shareholder Portal permissions (config/permissions.php group "Shareholder Portal").
     *
     * @var list<string>
     */
    private const KEYS = [
        'shareholder.portal', 'shareholder.profile', 'shareholder.capital.view', 'shareholder.capital.submit',
        'shareholder.dividends.view', 'shareholder.statements', 'shareholder.directory',
    ];

    /**
     * Every company gets the system role `shareholder` holding ONLY the portal permissions (no staff permission). The
     * portal permissions are effective only for an account linked to a shareholder record (AccessControl), so no staff
     * role receives them.
     */
    public function up(): void
    {
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $roleId = DB::table('roles')->where('company_id', $companyId)->where('key', 'shareholder')->value('id');
            if ($roleId === null) {
                $roleId = DB::table('roles')->insertGetId([
                    'company_id' => $companyId,
                    'key' => 'shareholder',
                    'name' => 'Shareholder',
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('role_permissions')->where('role_id', $roleId)->delete();
            DB::table('role_permissions')->insert(array_map(
                fn (string $permission): array => ['role_id' => $roleId, 'permission' => $permission],
                self::KEYS,
            ));
        }
    }

    /**
     * Reverse the migrations (the role is kept while accounts still use it).
     */
    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', self::KEYS)->delete();

        $unused = DB::table('roles')->where('key', 'shareholder')->whereNotExists(
            fn ($query) => $query->selectRaw('1')->from('employees')->whereColumn('employees.role_id', 'roles.id'),
        )->pluck('id');
        DB::table('roles')->whereIn('id', $unused)->delete();
    }
};

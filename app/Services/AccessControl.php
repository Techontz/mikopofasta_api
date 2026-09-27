<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use Illuminate\Database\Eloquent\Builder;

/**
 * Role permissions and data scoping (company / zone / branch).
 */
class AccessControl
{
    /**
     * Seed the default roles from config/permissions.php for a company.
     */
    public function seedRoles(Company $company): void
    {
        foreach (config('permissions.roles') as $key => $definition) {
            $role = Role::updateOrCreate(
                ['company_id' => $company->id, 'key' => $key],
                ['name' => $definition['name'], 'is_system' => true],
            );

            $permissions = $definition['permissions'] === ['*'] ? $this->implicitPermissions() : $definition['permissions'];
            foreach ($permissions as $permission) {
                $role->permissions()->firstOrCreate(['permission' => $permission]);
            }
        }
    }

    /**
     * Effective permissions: the role's permissions plus the employee's granted overrides minus the revoked ones.
     * The system Super Admin always holds every permission except the explicit-only ones (other overrides do not apply). An
     * explicit-only permission counts for a Super Admin only when it is granted visibly — stored on the Super Admin role or
     * granted by an employee override (and not revoked by one) — so it always shows in this list and
     * {@see self::explicitlyGranted()} never honours anything this list does not show. Unknown keys are ignored.
     *
     * @return list<string>
     */
    public function permissionsFor(Employee $employee): array
    {
        $portal = $this->shareholderPortalPermissionsFor($employee);

        if ($employee->isShareholderAccount()) {
            return $portal;
        }

        return $this->withoutCompanyMoneyUnlessOwner($employee, $this->staffPermissionsFor($employee, $portal));
    }

    /**
     * @param  list<string>  $portal
     * @return list<string>
     */
    private function staffPermissionsFor(Employee $employee, array $portal): array
    {
        if ($employee->role?->key === 'super_admin') {
            $explicit = $employee->permissionOverrides->whereIn('permission', $this->explicitOnlyPermissions());
            $granted = $explicit->where('granted', true)->pluck('permission')->all();
            $revoked = $explicit->where('granted', false)->pluck('permission')->all();
            $effective = array_diff(array_unique([...$this->rolePermissionsFor($employee), ...$granted]), $revoked);

            return array_values(array_intersect(array_keys(config('permissions.permissions')), [...$effective, ...$portal]));
        }

        $overrides = $employee->permissionOverrides;
        $granted = $overrides->where('granted', true)->pluck('permission')->all();
        $revoked = $overrides->where('granted', false)->pluck('permission')->all();

        $staff = array_diff(array_unique([...$this->rolePermissionsFor($employee), ...$granted]), $revoked, $this->shareholderPortalPermissions());
        $effective = [...$staff, ...$portal];

        return array_values(array_intersect(array_keys(config('permissions.permissions')), $effective));
    }

    /**
     * Company money permissions (bank accounts and transfers) stay only with Super Admin, Admin or a shareholder of the company.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function withoutCompanyMoneyUnlessOwner(Employee $employee, array $permissions): array
    {
        if ($this->isCompanyOwner($employee)) {
            return $permissions;
        }

        return array_values(array_diff($permissions, config('permissions.company_money.permissions', [])));
    }

    /**
     * Whether the login may see company money: a Super Admin / Admin staff login, or a login linked to a shareholder of its company.
     */
    public function isCompanyOwner(Employee $employee): bool
    {
        if (in_array($employee->role?->key, config('permissions.company_money.roles', []), true) && ! $employee->isShareholderAccount()) {
            return true;
        }

        $holder = $employee->shareHolder;

        return $holder !== null && (int) $holder->company_id === (int) $employee->company_id;
    }

    /**
     * Shareholder Portal permission keys (config permissions.shareholder_portal).
     *
     * @return list<string>
     */
    public function shareholderPortalPermissions(): array
    {
        return array_values(config('permissions.shareholder_portal', []));
    }

    /**
     * Portal permissions come only from the link to a shareholder record: every portal key for a linked account (minus
     * the ones revoked by an employee override), none otherwise — whatever the role or overrides grant.
     *
     * @return list<string>
     */
    public function shareholderPortalPermissionsFor(Employee $employee): array
    {
        if ($employee->shareHolder === null) {
            return [];
        }

        $revoked = $employee->permissionOverrides->where('granted', false)->pluck('permission')->all();

        return array_values(array_diff($this->shareholderPortalPermissions(), $revoked));
    }

    /**
     * Permissions coming from the employee's role alone (no per-employee overrides).
     *
     * @return list<string>
     */
    public function rolePermissionsFor(Employee $employee): array
    {
        if ($employee->role === null) {
            return [];
        }

        if ($employee->role->key === 'super_admin') {
            $stored = $employee->role->permissions->pluck('permission')->all();

            return array_values(array_filter(
                array_keys(config('permissions.permissions')),
                fn (string $permission): bool => ! in_array($permission, $this->shareholderPortalPermissions(), true)
                    && (! in_array($permission, $this->explicitOnlyPermissions(), true) || in_array($permission, $stored, true)),
            ));
        }

        return $employee->role->permissions->pluck('permission')->values()->all();
    }

    /**
     * Permissions that are never implied (not by the Super Admin role, not by default roles): effective only when the
     * company grants them explicitly.
     *
     * @return list<string>
     */
    public function explicitOnlyPermissions(): array
    {
        return array_values(config('permissions.explicit_only', []));
    }

    /**
     * Every permission key except the explicit-only ones (what the Super Admin implicitly holds).
     *
     * @return list<string>
     */
    public function implicitPermissions(): array
    {
        return array_values(array_diff(array_keys(config('permissions.permissions')), $this->explicitOnlyPermissions(), $this->shareholderPortalPermissions()));
    }

    /**
     * Whether the permission was configured for this employee explicitly — an employee override granting it, or the
     * employee's role storing it (and no override revoking it). Never implied by the Super Admin role; used for
     * explicit-only permissions such as approvals.self_approve. Only a permission the employee effectively holds
     * ({@see self::permissionsFor()}) can count, so the Super Admin gets nothing that its permission list does not show.
     */
    public function explicitlyGranted(Employee $employee, string $permission): bool
    {
        if ($employee->isShareholderAccount() || ! $this->can($employee, $permission)) {
            return false;
        }

        $override = $employee->permissionOverrides->firstWhere('permission', $permission);
        if ($override !== null) {
            return (bool) $override->granted;
        }

        return $employee->role !== null && $employee->role->permissions->contains('permission', $permission);
    }

    /**
     * Store the employee's overrides so that their effective permissions become exactly $desired, and drop the
     * cached relations so the next check in this request resolves the new set.
     *
     * @param  list<string>  $desired
     */
    public function syncEmployeePermissions(Employee $employee, array $desired): void
    {
        $portal = $this->shareholderPortalPermissions();
        $desired = array_values(array_diff($desired, $portal));
        $role = array_values(array_diff($this->rolePermissionsFor($employee), $portal));
        $overrides = [
            ...array_fill_keys(array_values(array_diff($desired, $role)), true),
            ...array_fill_keys(array_values(array_diff($role, $desired)), false),
        ];

        // Portal permissions are link-derived and never stored as staff privilege overrides here.
        $employee->permissionOverrides()->whereNotIn('permission', [...array_keys($overrides), ...$portal])->delete();
        foreach ($overrides as $permission => $granted) {
            $employee->permissionOverrides()->updateOrCreate(['permission' => $permission], ['granted' => $granted]);
        }

        $this->forgetCachedPermissions($employee);
    }

    /**
     * Permissions are resolved from the employee's loaded relations (no application cache); unset them so a change
     * made in this request is visible immediately.
     */
    public function forgetCachedPermissions(Employee $employee): void
    {
        $employee->unsetRelation('permissionOverrides');
        $employee->unsetRelation('shareHolder');
        $employee->role?->unsetRelation('permissions');
    }

    public function can(Employee $employee, string $permission): bool
    {
        return in_array($permission, $this->permissionsFor($employee), true);
    }

    /**
     * Branch ids the employee may see; null means every branch of the company.
     *
     * @return list<int>|null
     */
    public function branchIds(Employee $employee): ?array
    {
        if ($employee->isShareholderAccount()) {
            return [];
        }

        return match ($employee->role?->scope ?? 'branch') {
            'company' => null,
            'zone' => $employee->zone_id ? Branch::where('zone_id', $employee->zone_id)->pluck('id')->all() : [],
            default => $employee->branch_id ? [(int) $employee->branch_id] : [],
        };
    }

    /**
     * Restrict a query on a table with a branch_id column to the employee's data scope.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scope(Builder $query, Employee $employee, string $column = 'branch_id'): Builder
    {
        $query->where($query->getModel()->qualifyColumn('company_id'), $employee->company_id);

        $branchIds = $this->branchIds($employee);

        return $branchIds === null ? $query : $query->whereIn($query->getModel()->qualifyColumn($column), $branchIds);
    }
}

<?php

namespace App\Services;

use App\Http\Middleware\ApplyActiveRole;
use App\Models\Employee;
use App\Models\Role;
use App\Services\Approvals\SegregationOfDuties;
use Illuminate\Support\Collection;

/**
 * Who may give which role (enhancement §12.3), applied by every path that assigns roles — Settings → Roles, the HRM staff
 * form, the HRM role list, additional roles — and by staff privileges:
 *
 * - Roles & Permissions (editing what a role may do) is for the Super Admin only;
 * - only a Super Admin gives or removes Super Admin; the Shareholder role comes only with a shareholder login;
 * - outside Super Admin and Admin (e.g. HR) nobody gives or removes the protected roles — HR, Admin, Super Admin, Finance,
 *   Credit Officer, Shareholder — nor changes the role or privileges of an employee who holds one.
 *
 * The acting role is the one the session currently acts as ({@see ApplyActiveRole}).
 */
class RoleAssignmentRules
{
    /**
     * @var list<string>
     */
    public const PROTECTED = ['hr', 'admin', 'super_admin', 'finance', 'credit_officer', 'shareholder'];

    public static function managesRolesAndPermissions(Employee $actor): bool
    {
        return SegregationOfDuties::isSuperAdmin($actor);
    }

    public static function mayAssignProtectedRoles(Employee $actor): bool
    {
        return ! $actor->isShareholderAccount() && in_array($actor->role?->key, ['super_admin', 'admin'], true);
    }

    /**
     * Why the actor may not move an employee from one role to another, or null when they may.
     */
    public static function blockedReason(Employee $actor, ?Role $from, ?Role $to): ?string
    {
        return match (true) {
            $to?->key === 'shareholder' => 'The Shareholder role is assigned only by creating a shareholder login (Capital → Shareholders).',
            ($to?->key === 'super_admin' || $from?->key === 'super_admin') && ! SegregationOfDuties::isSuperAdmin($actor) => 'Only a Super Admin can assign the Super Admin role.',
            ! self::mayAssignProtectedRoles($actor) && in_array($to?->key, self::PROTECTED, true) => "You cannot assign the {$to->name} role. Ask an Admin.",
            ! self::mayAssignProtectedRoles($actor) && in_array($from?->key, self::PROTECTED, true) => "You cannot change the role of an employee who holds the {$from->name} role. Ask an Admin.",
            default => null,
        };
    }

    public static function assertAssignable(Employee $actor, ?Role $from, ?Role $to): void
    {
        $reason = self::blockedReason($actor, $from, $to);
        abort_if($reason !== null, $to?->key === 'shareholder' ? 422 : 403, (string) $reason);
    }

    /**
     * Whether the actor may change this employee's privileges: never a protected employee unless the actor is Admin or
     * Super Admin.
     */
    public static function mayEditEmployee(Employee $actor, Employee $employee): bool
    {
        if (self::mayAssignProtectedRoles($actor)) {
            return true;
        }

        return $employee->assignedRoles()->pluck('key')->intersect(self::PROTECTED)->isEmpty();
    }

    /**
     * The roles the actor may give.
     *
     * @param  Collection<int, Role>  $roles
     * @return Collection<int, Role>
     */
    public static function assignable(Employee $actor, Collection $roles): Collection
    {
        return $roles->filter(fn (Role $role): bool => self::blockedReason($actor, null, $role) === null)->values();
    }
}

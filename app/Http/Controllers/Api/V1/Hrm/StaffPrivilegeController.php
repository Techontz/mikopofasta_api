<?php

namespace App\Http\Controllers\Api\V1\Hrm;

use App\Http\Requests\Api\Hrm\StaffPrivilegesRequest;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * HRM → All Active Staff → Privilege (live admin/privillage/:id): the permissions one employee holds.
 *
 * Live adds/removes module privileges per user (a fixed list of 16 items). Here each item (config permissions.privileges)
 * maps to real permission keys; the employee's role supplies the defaults and the page stores per-employee overrides,
 * resolved by AccessControl (effective = role + granted − revoked) so every Gate check honours them. A save sends the
 * complete effective set and replaces the override set atomically. Guards: nobody edits their
 * own privileges, the Super Admin always has full access, and an administrator can only grant or revoke permissions
 * they hold themselves.
 */
class StaffPrivilegeController extends HrmController
{
    public function __construct(private readonly AccessControl $access) {}

    public function show(Employee $employee): JsonResponse
    {
        $this->authorizeAny('hrm.staff_privileges', 'users.manage', 'hrm.manage');
        $this->ensureVisible($employee);

        return response()->json(['data' => $this->payload($employee)]);
    }

    public function update(StaffPrivilegesRequest $request, Employee $employee): JsonResponse
    {
        $actor = $this->currentEmployee();

        abort_if($employee->is($actor), 403, 'You cannot change your own privileges.');
        abort_if($employee->role?->key === 'super_admin', 422, 'The Super Admin has full access; privileges cannot be changed.');

        $before = $this->access->permissionsFor($employee);
        $desired = $request->permissions();
        $changed = array_values(array_unique([...array_diff($desired, $before), ...array_diff($before, $desired)]));

        $notHeld = array_values(array_diff($changed, $this->access->permissionsFor($actor)));
        abort_if($notHeld !== [], 403, 'You cannot grant or revoke privileges you do not hold: '.implode(', ', $notHeld).'.');

        if ($changed !== []) {
            // One transaction replaces the employee's whole override set (and writes the audit row): any failure rolls
            // everything back, so the previous privileges stay intact. The row lock serialises concurrent saves.
            DB::transaction(function () use ($employee, $actor, $before, $desired, $request): void {
                Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
                $this->access->syncEmployeePermissions($employee, $desired);

                AuditLog::create([
                    'company_id' => $employee->company_id,
                    'employee_id' => $actor->id,
                    'action' => 'Employee.privileges_updated',
                    'auditable_type' => $employee->getMorphClass(),
                    'auditable_id' => $employee->id,
                    'before' => ['permissions' => $before],
                    'after' => [
                        'granted' => array_values(array_diff($desired, $before)),
                        'revoked' => array_values(array_diff($before, $desired)),
                        'permissions' => $this->access->permissionsFor($employee),
                    ],
                    'ip_address' => $request->ip(),
                ]);
            });
        }

        return $this->message('Staff Privileges Updated successfully', 200, ['data' => $this->payload($employee)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Employee $employee): array
    {
        $employee->load(['branch', 'role.permissions', 'permissionOverrides']);
        $actor = $this->currentEmployee();
        $isSuperAdmin = $employee->role?->key === 'super_admin';

        $readOnlyReason = match (true) {
            ! Gate::allows('hrm.staff_privileges') => 'You can view these privileges but not change them.',
            $employee->is($actor) => 'You cannot change your own privileges.',
            $isSuperAdmin => 'The Super Admin has full access; privileges cannot be changed.',
            default => null,
        };

        $overrides = $employee->permissionOverrides;

        return [
            'employee' => [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
                'username' => $employee->username,
                'phone' => $employee->phone,
                'branch' => $employee->branch?->name,
                'position' => $employee->position,
                'status' => $employee->status,
                'role' => $employee->role ? ['id' => $employee->role->id, 'key' => $employee->role->key, 'name' => $employee->role->name, 'scope' => $employee->role->scope] : null,
                'zone_id' => $employee->zone_id,
            ],
            // Live privilege list structure (groups → items → permission keys) from config/permissions.php.
            'privilege_groups' => collect(config('permissions.privileges'))
                ->map(fn (array $group): array => [
                    'key' => $group['key'],
                    'label' => $group['label'],
                    'items' => collect($group['items'])->map(fn (array $item): array => [
                        'key' => $item['key'],
                        'label' => $item['label'],
                        'permissions' => array_values($item['permissions']),
                    ])->values(),
                ])
                ->values(),
            'catalogue' => collect(config('permissions.permissions'))
                ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label, 'group' => explode('.', $key)[0]])
                ->values(),
            'role_permissions' => $this->access->rolePermissionsFor($employee),
            'granted' => $isSuperAdmin ? [] : $overrides->where('granted', true)->pluck('permission')->values(),
            'revoked' => $isSuperAdmin ? [] : $overrides->where('granted', false)->pluck('permission')->values(),
            'permissions' => $this->access->permissionsFor($employee),
            'can_edit' => $readOnlyReason === null,
            'read_only_reason' => $readOnlyReason,
            'actor_permissions' => $this->access->permissionsFor($actor),
            // Same rules as Settings → Roles & Permissions → assign role (PUT settings/employees/{employee}/role).
            'can_change_role' => (Gate::allows('users.manage') || Gate::allows('hrm.manage')) && ! $employee->is($actor)
                && (! $isSuperAdmin || $actor->role?->key === 'super_admin'),
        ];
    }
}

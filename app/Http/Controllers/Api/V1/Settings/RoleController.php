<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Settings\EmployeeRoleRequest;
use App\Http\Requests\Api\Settings\RolePermissionsRequest;
use App\Http\Resources\Api\V1\Settings\RoleResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Role;
use App\Services\RoleAssignmentRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Settings → Roles & Permissions (Documents: ACCOUNT OVERVIEW "ROLE CONTROL"). Roles are per company with editable
 * permission sets from config/permissions.php; the system Super Admin role always has full access and is locked.
 */
class RoleController extends ApiController
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorizeRolesAndPermissions();

        return RoleResource::collection(Role::where('company_id', $this->currentEmployee()->company_id)
            ->with('permissions')
            ->withCount('employees')
            ->orderBy('id')
            ->get());
    }

    /**
     * Permission catalogue grouped by module prefix (e.g. "loans").
     */
    public function permissions(): JsonResponse
    {
        $this->authorizeRolesAndPermissions();

        return response()->json(['data' => collect(config('permissions.permissions'))
            ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label, 'group' => explode('.', $key)[0]])
            ->values()]);
    }

    public function updatePermissions(RolePermissionsRequest $request, Role $role): JsonResponse
    {
        $this->authorizeRolesAndPermissions();
        abort_unless((int) $role->company_id === (int) $this->currentEmployee()->company_id, 404);

        if ($role->key === 'super_admin') {
            return $this->message('System Super Admin role cannot be modified', 422);
        }

        $before = $role->permissions()->pluck('permission')->sort()->values()->all();
        $after = collect($request->input('permissions', []))->unique()->sort()->values()->all();

        DB::transaction(function () use ($role, $before, $after, $request): void {
            $role->permissions()->whereNotIn('permission', $after)->delete();
            foreach (array_diff($after, $before) as $permission) {
                $role->permissions()->create(['permission' => $permission]);
            }

            AuditLog::create([
                'company_id' => $role->company_id,
                'employee_id' => $this->currentEmployee()->id,
                'action' => 'Role.permissions_updated',
                'auditable_type' => $role->getMorphClass(),
                'auditable_id' => $role->id,
                'before' => ['permissions' => $before],
                'after' => ['permissions' => $after],
                'ip_address' => $request->ip(),
            ]);
        });

        return $this->message('Role Permissions Updated successfully', 200, ['data' => new RoleResource($role->load('permissions'))]);
    }

    /**
     * Inferred: only a Super Admin may grant or revoke the Super Admin role, and nobody may change their own role. HR may not
     * give or take the protected roles ({@see RoleAssignmentRules}).
     */
    public function assignEmployeeRole(EmployeeRoleRequest $request, Employee $employee): JsonResponse
    {
        $this->authorizeAny('users.manage', 'hrm.manage');

        $current = $this->currentEmployee();
        $role = $request->selectedRole();

        abort_if($employee->id === $current->id, 403, 'You cannot change your own role.');
        abort_if($employee->isShareholderAccount(), 422, 'Shareholder portal accounts cannot be given a staff role.');
        RoleAssignmentRules::assertAssignable($current, $employee->primaryRoleId() ? Role::find($employee->primaryRoleId()) : null, $role);

        $before = ['role_id' => $employee->role_id, 'zone_id' => $employee->zone_id];
        $after = ['role_id' => $role->id, 'zone_id' => $role->scope === 'zone' ? $request->integer('zone_id') : null];

        DB::transaction(function () use ($employee, $before, $after, $request, $current): void {
            $employee->update($after);

            AuditLog::create([
                'company_id' => $employee->company_id,
                'employee_id' => $current->id,
                'action' => 'Employee.role_assigned',
                'auditable_type' => $employee->getMorphClass(),
                'auditable_id' => $employee->id,
                'before' => $before,
                'after' => $after,
                'ip_address' => $request->ip(),
            ]);
        });

        return $this->message('Staff Role Updated successfully');
    }

    /**
     * PUT settings/employees/{employee}/roles — the roles the employee holds besides the primary one (multi-role, §13).
     * Each role added or removed follows {@see RoleAssignmentRules}; a session acting as a removed role falls back to the
     * primary role on its next request.
     */
    public function syncAdditionalRoles(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeAny('users.manage', 'hrm.manage');
        $current = $this->currentEmployee();
        abort_unless((int) $employee->company_id === (int) $current->company_id, 404);
        abort_if($employee->id === $current->id, 403, 'You cannot change your own roles.');
        abort_if($employee->isShareholderAccount(), 422, 'Shareholder portal accounts cannot be given a staff role.');

        $validated = $request->validate([
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['integer', 'distinct', Rule::exists('roles', 'id')->where('company_id', $current->company_id)],
        ]);

        $before = $employee->additionalRoles()->pluck('roles.id')->map(fn ($id): int => (int) $id)->all();
        $after = collect($validated['role_ids'])->map(fn ($id): int => (int) $id)->reject(fn (int $id): bool => $id === $employee->primaryRoleId())->unique()->values()->all();
        foreach (Role::whereKey([...array_diff($after, $before), ...array_diff($before, $after)])->get() as $role) {
            RoleAssignmentRules::assertAssignable($current, in_array($role->id, $before, true) ? $role : null, in_array($role->id, $after, true) ? $role : null);
        }

        DB::transaction(function () use ($employee, $before, $after, $current, $request): void {
            $employee->additionalRoles()->sync(collect($after)->mapWithKeys(fn (int $id): array => [$id => ['assigned_by' => $current->id]])->all());

            AuditLog::create([
                'company_id' => $employee->company_id,
                'employee_id' => $current->id,
                'action' => 'Employee.additional_roles_updated',
                'auditable_type' => $employee->getMorphClass(),
                'auditable_id' => $employee->id,
                'before' => ['role_ids' => $before],
                'after' => ['role_ids' => $after],
                'ip_address' => $request->ip(),
            ]);
        });

        return $this->message('Staff Roles Updated successfully', 200, ['data' => ['role_ids' => $after]]);
    }

    private function authorizeRolesAndPermissions(): void
    {
        abort_unless(RoleAssignmentRules::managesRolesAndPermissions($this->currentEmployee()), 403, 'You do not have permission to perform this action.');
    }
}

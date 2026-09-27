<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Settings\EmployeeRoleRequest;
use App\Http\Requests\Api\Settings\RolePermissionsRequest;
use App\Http\Resources\Api\V1\Settings\RoleResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Roles & Permissions (Documents: ACCOUNT OVERVIEW "ROLE CONTROL"). Roles are per company with editable
 * permission sets from config/permissions.php; the system Super Admin role always has full access and is locked.
 */
class RoleController extends ApiController
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorizeAny('users.manage');

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
        $this->authorizeAny('users.manage');

        return response()->json(['data' => collect(config('permissions.permissions'))
            ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label, 'group' => explode('.', $key)[0]])
            ->values()]);
    }

    public function updatePermissions(RolePermissionsRequest $request, Role $role): JsonResponse
    {
        $this->authorizeAny('users.manage');

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
     * Inferred: only a Super Admin may grant or revoke the Super Admin role, and nobody may change their own role.
     */
    public function assignEmployeeRole(EmployeeRoleRequest $request, Employee $employee): JsonResponse
    {
        $this->authorizeAny('users.manage', 'hrm.manage');

        $current = $this->currentEmployee();
        $role = $request->selectedRole();

        abort_if($employee->id === $current->id, 403, 'You cannot change your own role.');
        abort_if($employee->isShareholderAccount(), 422, 'Shareholder portal accounts cannot be given a staff role.');
        abort_if($role->key === 'shareholder', 422, 'The Shareholder role is assigned only by creating a shareholder login (Capital → Shareholders).');
        abort_if(($role->key === 'super_admin' || $employee->role?->key === 'super_admin') && $current->role?->key !== 'super_admin', 403, 'Only a Super Admin can assign the Super Admin role.');

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
}

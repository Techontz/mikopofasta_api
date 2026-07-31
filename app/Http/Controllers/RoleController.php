<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\Actions\UpdateRolePermissionsAction;
use App\Http\Requests\Roles\UpdateRolePermissionsRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Roles and the permission matrix.
 *
 * Roles are a fixed, seeded set (§14) — there is intentionally no store() or
 * destroy(). Adding a role would mean inventing a business rule about what it
 * may do, and the RBAC model in §14 is closed.
 */
final class RoleController extends Controller
{
    /**
     * GET /api/v1/roles
     *
     * Powers the roles list and the permission matrix, so it returns every
     * role with its live grants — unpaginated, because there are exactly
     * eleven and the matrix screen needs all of them at once.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $roles = Role::query()
            ->with('permissions')
            ->withCount('assignedUsers')
            ->orderBy('id')
            ->get();

        return ApiResponse::data(RoleResource::collection($roles));
    }

    /**
     * GET /api/v1/roles/{role}
     */
    public function show(Request $request, Role $role): JsonResponse
    {
        $this->authorize('view', $role);

        return ApiResponse::data(
            new RoleResource($role->load('permissions')->loadCount('assignedUsers')),
        );
    }

    /**
     * PUT /api/v1/roles/{role}/permissions
     *
     * Takes the complete permission set the role should hold, matching how the
     * frontend's matrix computes the next state before calling.
     */
    public function updatePermissions(
        UpdateRolePermissionsRequest $request,
        Role $role,
        UpdateRolePermissionsAction $action,
    ): JsonResponse {
        $this->authorize('updatePermissions', $role);

        /** @var list<string> $permissions */
        $permissions = $request->validated('permissions');

        $updated = $action->handle($role, $permissions, $this->actor($request));

        return ApiResponse::data(
            new RoleResource($updated->load('permissions')->loadCount('assignedUsers')),
        );
    }
}

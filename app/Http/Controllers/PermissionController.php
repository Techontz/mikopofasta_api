<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\Enums\PermissionName;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use App\Models\Role;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The permission catalogue that the matrix screen renders its columns from.
 *
 * Read-only: the permission set is fixed by §14 and seeded from
 * PermissionName. What changes is which role holds which permission.
 */
final class PermissionController extends Controller
{
    /**
     * GET /api/v1/permissions
     *
     * Returns the flat list plus the grouping the frontend's PERMISSION_GROUPS
     * uses, so the UI does not need its own copy of that mapping.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $permissions = Permission::query()->orderBy('id')->get();

        $groups = array_values(array_map(
            static fn (string $group): array => [
                'label' => $group,
                'permissions' => array_values(array_map(
                    static fn (PermissionName $p): string => $p->value,
                    array_filter(
                        PermissionName::cases(),
                        static fn (PermissionName $p): bool => $p->group() === $group,
                    ),
                )),
            ],
            PermissionName::groupOrder(),
        ));

        return ApiResponse::data(
            PermissionResource::collection($permissions),
            ['groups' => $groups],
        );
    }
}

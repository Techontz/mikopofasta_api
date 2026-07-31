<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A role and its current permission grants, as consumed by the roles list and
 * permission matrix screens (features/admin/roles/).
 *
 * `permissions` reflects the live `role_has_permissions` rows, not the seed
 * defaults — the matrix is editable, so anything else would show stale state.
 *
 * @mixin Role
 */
final class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'label' => $this->label(),
            'description' => $this->description(),

            // False for super_admin, whose grants are fixed.
            'editable' => $this->isEditable(),

            'permissions' => $this->permissions->pluck('name')->sort()->values()->all(),
            'userCount' => $this->whenCounted('assignedUsers'),
        ];
    }
}

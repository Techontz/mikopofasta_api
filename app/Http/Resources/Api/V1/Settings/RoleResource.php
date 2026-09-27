<?php

namespace App\Http\Resources\Api\V1\Settings;

use App\Models\Role;
use App\Services\AccessControl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $permissions = $this->key === 'super_admin'
            ? app(AccessControl::class)->implicitPermissions()
            : $this->whenLoaded('permissions', fn () => $this->permissions->pluck('permission')->sort()->values()->all(), []);

        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'scope' => $this->scope,
            'is_system' => $this->is_system,
            'is_locked' => $this->key === 'super_admin',
            'permissions' => $permissions,
            'employees_count' => $this->whenCounted('employees'),
        ];
    }
}

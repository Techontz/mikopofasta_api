<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Auth\Enums\PermissionName;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Backend spec §2.1 — `permissions`.
 *
 * The permission set is fixed and seeded from PermissionName (§14); there is
 * no endpoint that creates or deletes one. What is editable is which
 * permissions a role holds — see RoleController::updatePermissions().
 *
 * @property string $name
 */
class Permission extends SpatiePermission
{
    public function toPermissionName(): PermissionName
    {
        return PermissionName::from($this->name);
    }

    public function label(): string
    {
        return $this->toPermissionName()->label();
    }

    public function group(): string
    {
        return $this->toPermissionName()->group();
    }
}

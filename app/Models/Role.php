<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Auth\Enums\RoleName;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Backend spec §2.1 — `roles`.
 *
 * Extends Spatie's model so the permission engine keeps working, while adding
 * the `users` relation implied by the spec's `users.role_id` column and the
 * enum-backed helpers the rest of the app uses.
 *
 * Roles are a fixed, seeded set (§14) — there is no create/delete endpoint for
 * them. Only the permission grants attached to a role are editable.
 *
 * @property string $name
 */
class Role extends SpatieRole
{
    /**
     * Users whose authoritative `role_id` points here.
     *
     * Deliberately NOT Spatie's inherited `users()` relation, which reads the
     * `model_has_roles` pivot. That pivot is derived state kept in sync by
     * User::booted(); `users.role_id` is the source of truth (spec §2.1).
     * The name differs because Spatie's `users()` is a BelongsToMany and
     * cannot be overridden with a HasMany.
     *
     * @return HasMany<User, $this>
     */
    public function assignedUsers(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function toRoleName(): RoleName
    {
        return RoleName::from($this->name);
    }

    public function label(): string
    {
        return $this->toRoleName()->label();
    }

    public function description(): string
    {
        return $this->toRoleName()->description();
    }

    /**
     * Super Admin's grants are fixed — see RoleName::isEditable().
     */
    public function isEditable(): bool
    {
        return $this->toRoleName()->isEditable();
    }
}

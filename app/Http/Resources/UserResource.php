<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The administrative `users` record — the shape of `UserSchema` in the
 * frontend's types/user.ts, which validates this payload with Zod.
 *
 * Two conventions the frontend pins and this resource must honour:
 *
 *  - Keys are camelCase (`branchId`, `lastLoginAt`, `deletedAt`).
 *  - Every id is a STRING. `UserSchema.id` is `z.string()`, as are
 *    `branchId` / `zoneId` / `regionId` / `createdBy`. Emitting a bare
 *    integer fails validation at the frontend boundary.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'role' => $this->role->name,
            'branchId' => self::id($this->branch_id),
            'zoneId' => self::id($this->zone_id),
            'regionId' => self::id($this->region_id),
            'status' => $this->status->value,
            'lastLoginAt' => $this->last_login_at?->toIso8601String(),
            'createdBy' => self::id($this->created_by),
            'deletedAt' => $this->deleted_at?->toIso8601String(),
        ];
    }

    private static function id(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The session profile — `AuthenticatedUser` in the frontend's types/auth.ts.
 *
 * Narrower than UserResource: this is what Next.js encrypts into its BFF
 * session cookie (frontend spec §2), so it carries only what proxy.ts needs to
 * gate routes without calling back to Laravel.
 *
 * `permissions` is the resolved effective set (role grants ∪ per-user grants).
 * It is included even though the frontend also has a local copy of the §14
 * matrix, because that matrix is editable at runtime through the permission
 * matrix screen — once an administrator changes it, the server's answer is the
 * only correct one. Frontend spec §2 step 2 lists `permissions[]` in the
 * cookie payload for exactly this reason.
 *
 * @mixin User
 */
final class AuthenticatedUserResource extends JsonResource
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

            // Grants layered on top of the role — spec §13/§14 Decision 1.
            'extraPermissions' => $this->extraPermissionNames(),

            // Role grants ∪ extraPermissions, already resolved server-side.
            'permissions' => $this->effectivePermissionNames(),

            'avatarInitials' => $this->avatarInitials(),
            'lastLoginAt' => $this->last_login_at?->toIso8601String(),
        ];
    }

    private static function id(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}

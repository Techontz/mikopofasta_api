<?php

declare(strict_types=1);

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\DTOs\UpdateUserData;
use App\Domain\Auth\Services\TokenIssuer;
use App\Enums\AuditAction;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Updates a user's profile, role and scope (PUT /users/{user}).
 */
final class UpdateUserAction
{
    public function __construct(
        private readonly TokenIssuer $tokens,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $user, UpdateUserData $data, User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor): User {
            $role = Role::query()->where('name', $data->role)->firstOrFail();

            $before = $this->snapshot($user);
            $roleChanged = $user->role_id !== $role->getKey();

            $user->update([
                'name' => $data->name,
                'phone' => $data->phone,
                'email' => $data->email,
                'role_id' => $role->getKey(),
                'branch_id' => $data->branchId,
                'zone_id' => $data->zoneId,
                'region_id' => $data->regionId,
            ]);

            $user->load('role');

            /*
             * Sanctum tokens carry the user's permissions as abilities (spec
             * §1). A role change therefore makes every existing token stale —
             * and a stale token would keep granting the OLD role's abilities,
             * which is exactly the privilege-escalation case the ability
             * scoping exists to prevent. Revoking forces re-authentication.
             */
            if ($roleChanged) {
                $this->tokens->revokeAll($user);
            }

            $this->audit->log(
                AuditAction::UserUpdated,
                $user,
                before: $before,
                after: $this->snapshot($user),
                actor: $actor,
            );

            return $user;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(User $user): array
    {
        return [
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'role' => $user->role->name,
            'branch_id' => $user->branch_id,
            'zone_id' => $user->zone_id,
            'region_id' => $user->region_id,
            'status' => $user->status->value,
        ];
    }
}

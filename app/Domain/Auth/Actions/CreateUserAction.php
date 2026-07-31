<?php

declare(strict_types=1);

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\DTOs\CreateUserData;
use App\Domain\Auth\Enums\UserStatus;
use App\Enums\AuditAction;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Provisions a user account (POST /users).
 *
 * The role is resolved from its name, not an id, so the API speaks the same
 * vocabulary as the frontend — which sends `"role": "loan_officer"`, never a
 * numeric id it has no way of knowing.
 */
final class CreateUserAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(CreateUserData $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            $role = Role::query()->where('name', $data->role)->firstOrFail();

            $user = User::query()->create([
                'name' => $data->name,
                'phone' => $data->phone,
                'email' => $data->email,
                'password' => $data->password,
                'role_id' => $role->getKey(),
                'branch_id' => $data->branchId,
                'zone_id' => $data->zoneId,
                'region_id' => $data->regionId,
                'status' => UserStatus::Active,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->log(
                AuditAction::UserCreated,
                $user,
                after: $this->snapshot($user),
                actor: $actor,
            );

            return $user->load('role');
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

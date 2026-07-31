<?php

declare(strict_types=1);

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Enums\UserStatus;
use App\Domain\Auth\Exceptions\CannotModifyOwnAccountException;
use App\Domain\Auth\Services\TokenIssuer;
use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Enables or disables an account (PATCH /users/{user}/status).
 *
 * Mirrors the frontend's setUserStatus(), including its self-modification
 * guard.
 */
final class SetUserStatusAction
{
    public function __construct(
        private readonly TokenIssuer $tokens,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $user, UserStatus $status, User $actor): User
    {
        if ($user->is($actor)) {
            throw CannotModifyOwnAccountException::status();
        }

        return DB::transaction(function () use ($user, $status, $actor): User {
            $before = $user->status;

            $user->update(['status' => $status]);

            /*
             * Suspension has to take effect immediately. Without this the user
             * keeps a valid bearer token and stays fully operational until it
             * expires — the block would only apply at next login, which is
             * precisely when it does not matter.
             */
            if ($status === UserStatus::Suspended) {
                $this->tokens->revokeAll($user);
            }

            $this->audit->log(
                AuditAction::UserStatusChanged,
                $user,
                before: ['status' => $before->value],
                after: ['status' => $status->value],
                actor: $actor,
            );

            return $user->load('role');
        });
    }
}

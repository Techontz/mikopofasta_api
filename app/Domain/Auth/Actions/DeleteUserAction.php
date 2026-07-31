<?php

declare(strict_types=1);

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Exceptions\CannotModifyOwnAccountException;
use App\Domain\Auth\Services\TokenIssuer;
use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Soft-deletes a user (DELETE /users/{user}).
 *
 * Soft delete, never a hard one: spec §2's cross-cutting rule is that nothing
 * financial is ever destroyed, and a user id is referenced from
 * `audit_logs.user_id`, `loans.officer_id`, `payments.teller_id` and others.
 * Removing the row would orphan the audit trail that makes those records
 * meaningful.
 */
final class DeleteUserAction
{
    public function __construct(
        private readonly TokenIssuer $tokens,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw CannotModifyOwnAccountException::deletion();
        }

        DB::transaction(function () use ($user, $actor): void {
            $this->audit->log(
                AuditAction::UserDeleted,
                $user,
                before: ['phone' => $user->phone, 'status' => $user->status->value],
                actor: $actor,
            );

            $this->tokens->revokeAll($user);

            $user->delete();
        });
    }
}

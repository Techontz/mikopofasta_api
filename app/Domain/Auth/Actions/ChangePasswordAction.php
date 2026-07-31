<?php

declare(strict_types=1);

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Exceptions\CurrentPasswordIncorrectException;
use App\Domain\Auth\Services\TokenIssuer;
use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Changes the authenticated user's own password.
 *
 * Re-verifying the current password matters even though the caller is already
 * authenticated: it stops someone who found an unlocked machine from taking
 * permanent ownership of the account.
 */
final class ChangePasswordAction
{
    public function __construct(
        private readonly TokenIssuer $tokens,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return string the replacement token for the current device
     */
    public function handle(User $user, string $currentPassword, string $newPassword): string
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw new CurrentPasswordIncorrectException;
        }

        return DB::transaction(function () use ($user, $newPassword): string {
            $user->forceFill(['password' => $newPassword])->save();

            /*
             * Every existing token dies with the old password. If the reason
             * for the change is that the old one leaked, leaving other
             * sessions alive would defeat the exercise.
             */
            $this->tokens->revokeAll($user);

            $this->audit->log(AuditAction::PasswordChanged, $user, actor: $user);

            // The caller just invalidated their own token; hand back a new one
            // so a password change does not read as a random logout.
            return $this->tokens->issue($user)->plainTextToken;
        });
    }
}

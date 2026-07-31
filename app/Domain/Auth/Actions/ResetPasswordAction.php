<?php

declare(strict_types=1);

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Exceptions\AccountSuspendedException;
use App\Domain\Auth\Exceptions\InvalidResetTokenException;
use App\Domain\Auth\Services\TokenIssuer;
use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Completes the password reset flow using the emailed token.
 */
final class ResetPasswordAction
{
    public function __construct(
        private readonly TokenIssuer $tokens,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(string $email, string $token, string $newPassword): void
    {
        $status = Password::reset(
            [
                'email' => $email,
                'token' => $token,
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ],
            function (User $user) use ($newPassword): void {
                if (! $user->canAuthenticate()) {
                    throw new AccountSuspendedException;
                }

                DB::transaction(function () use ($user, $newPassword): void {
                    $user->forceFill(['password' => $newPassword])->save();

                    // A reset exists because the old credential is not trusted;
                    // every session established with it must die.
                    $this->tokens->revokeAll($user);

                    $this->audit->log(AuditAction::PasswordReset, $user, actor: $user);
                });
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new InvalidResetTokenException;
        }
    }
}

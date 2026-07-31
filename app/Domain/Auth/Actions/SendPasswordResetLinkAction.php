<?php

declare(strict_types=1);

namespace App\Domain\Auth\Actions;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Password;

/**
 * Starts the password reset flow.
 *
 * The response is deliberately identical whether or not the email matches an
 * account, so this endpoint cannot be used to enumerate registered users. The
 * audit row records what actually happened.
 */
final class SendPasswordResetLinkAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(string $email): void
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->audit->logAnonymous(
                AuditAction::PasswordResetRequested,
                User::class,
                $email,
                ['outcome' => 'unknown_email'],
            );

            return;
        }

        if (! $user->canAuthenticate()) {
            // A suspended account must not be recoverable by its holder.
            $this->audit->log(
                AuditAction::PasswordResetRequested,
                $user,
                after: ['outcome' => 'account_suspended'],
                actor: $user,
            );

            return;
        }

        $status = Password::sendResetLink(['email' => $email]);

        $this->audit->log(
            AuditAction::PasswordResetRequested,
            $user,
            after: ['outcome' => $status],
            actor: $user,
        );
    }
}

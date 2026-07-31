<?php

declare(strict_types=1);

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Services\TokenIssuer;
use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Revokes the token used for the current request — frontend spec §2 step 5:
 * "Logout clears the cookie and calls Laravel's token-revocation endpoint."
 *
 * Only the current token is revoked, so signing out of one browser does not
 * sign the user out everywhere.
 */
final class LogoutAction
{
    public function __construct(
        private readonly TokenIssuer $tokens,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $this->audit->log(AuditAction::UserLoggedOut, $user, actor: $user);

            $this->tokens->revokeCurrent($user);
        });
    }
}

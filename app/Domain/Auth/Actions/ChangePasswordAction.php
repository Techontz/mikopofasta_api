<?php

declare(strict_types=1);

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Exceptions\CurrentPasswordIncorrectException;
use App\Domain\Auth\Services\TokenIssuer;
use App\Enums\AuditAction;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

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
        private readonly Request $request,
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

            /*
             * Told out of band, because the value is in the case where the
             * user did NOT do this: an unexplained password change is often
             * the only signal an account has been taken over.
             *
             * Best-effort and non-fatal. Sign-in here is by phone and an email
             * is optional, so a missing address must not roll back a password
             * change the user has already been told succeeded — and neither
             * must an unreachable mail server. The audit entry above is the
             * record that always exists.
             */
            if ($user->email !== null && $user->email !== '') {
                try {
                    $user->notify(new PasswordChangedNotification(
                        $this->request->ip() ?? 'an unknown address',
                        Date::now()->toDayDateTimeString(),
                    ));
                } catch (Throwable $e) {
                    report($e);
                }
            }

            // The caller just invalidated their own token; hand back a new one
            // so a password change does not read as a random logout.
            return $this->tokens->issue($user)->plainTextToken;
        });
    }
}

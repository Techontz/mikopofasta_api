<?php

declare(strict_types=1);

namespace App\Domain\Auth\Services;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\Date;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issues and revokes Sanctum personal access tokens.
 *
 * Spec §1 requires tokens to be scoped with abilities mirroring the issuing
 * user's permissions, "so a stolen token can't silently exceed the issuing
 * user's permissions". That is done here, in one place, so no caller can mint
 * an unscoped token by accident.
 */
final class TokenIssuer
{
    public const string DEFAULT_DEVICE_NAME = 'mikopofasta-web';

    public function issue(User $user, ?string $deviceName = null): NewAccessToken
    {
        return $user->createToken(
            name: $deviceName ?? self::DEFAULT_DEVICE_NAME,
            abilities: $user->effectivePermissionNames(),
            expiresAt: $this->expiry(),
        );
    }

    /**
     * Revokes only the token used for the current request, leaving the user's
     * other sessions (a second browser, a mobile device) intact.
     */
    public function revokeCurrent(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token !== null) {
            $token->delete();
        }
    }

    /**
     * Revokes every token the user holds. Used when credentials change — a
     * password change or reset must not leave an old session alive, since the
     * whole point may be that the old credentials were compromised.
     */
    public function revokeAll(User $user): void
    {
        $user->tokens()->delete();
    }

    private function expiry(): ?DateTimeInterface
    {
        $minutes = config('sanctum.expiration');

        return is_numeric($minutes) ? Date::now()->addMinutes((int) $minutes) : null;
    }
}

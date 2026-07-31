<?php

declare(strict_types=1);

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\DTOs\LoginData;
use App\Domain\Auth\Exceptions\AccountSuspendedException;
use App\Domain\Auth\Exceptions\InvalidCredentialsException;
use App\Domain\Auth\Services\TokenIssuer;
use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Authenticates by phone and issues a Sanctum token.
 *
 * Returns the user alongside the plain-text token; the token value exists only
 * for the duration of this response and is never retrievable again.
 */
final class LoginAction
{
    public function __construct(
        private readonly TokenIssuer $tokens,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{user: User, token: string}
     */
    public function handle(LoginData $data): array
    {
        $user = User::query()
            ->with('role')
            ->where('phone', $data->phone)
            ->first();

        if ($user === null || ! Hash::check($data->password, $user->password)) {
            /*
             * Recorded outside a transaction and before throwing, so the
             * attempt survives regardless of how the request unwinds. Only the
             * attempted phone number is kept — never the submitted password.
             */
            $this->audit->logAnonymous(
                AuditAction::UserLoginFailed,
                User::class,
                $data->phone,
                ['reason' => $user === null ? 'unknown_phone' : 'bad_password'],
            );

            throw new InvalidCredentialsException;
        }

        if (! $user->canAuthenticate()) {
            $this->audit->logAnonymous(
                AuditAction::UserLoginFailed,
                User::class,
                $data->phone,
                ['reason' => 'account_suspended'],
            );

            throw new AccountSuspendedException;
        }

        return DB::transaction(function () use ($user, $data): array {
            $token = $this->tokens->issue($user, $data->deviceName);

            $user->forceFill(['last_login_at' => Date::now()])->save();

            $this->audit->log(AuditAction::UserLoggedIn, $user, actor: $user);

            return [
                'user' => $user->refresh()->load('role'),
                'token' => $token->plainTextToken,
            ];
        });
    }
}

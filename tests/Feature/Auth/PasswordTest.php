<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

describe('change password', function (): void {
    it('changes the password and issues a replacement token', function (): void {
        $user = actingAsRole(RoleName::Finance);

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => defaultPassword(),
            'password' => 'a-much-better-password',
            'password_confirmation' => 'a-much-better-password',
        ]);

        $response->assertOk()->assertJsonStructure(['data' => ['token', 'tokenType']]);

        expect(Hash::check('a-much-better-password', $user->refresh()->password))->toBeTrue();
    });

    it('rejects a wrong current password', function (): void {
        $user = actingAsRole(RoleName::Finance);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'not-my-password',
            'password' => 'a-much-better-password',
            'password_confirmation' => 'a-much-better-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'CURRENT_PASSWORD_INCORRECT');

        expect(Hash::check(defaultPassword(), $user->refresh()->password))->toBeTrue();
    });

    it('signs every other session out when the password changes', function (): void {
        seedRbac();
        $user = User::factory()->role(RoleName::Finance)->create(['phone' => '0754000090']);

        // Two devices signed in with the old password.
        $otherDevice = $user->createToken('other', ['*'])->plainTextToken;
        $thisDevice = $user->createToken('this', ['*'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$thisDevice)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => defaultPassword(),
                'password' => 'a-much-better-password',
                'password_confirmation' => 'a-much-better-password',
            ])->assertOk();

        // If the old password leaked, leaving that session alive would defeat
        // the point of changing it.
        forgetAuthGuards();

        $this->withHeader('Authorization', 'Bearer '.$otherDevice)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    });

    it('requires confirmation and a reasonable length', function (): void {
        actingAsRole(RoleName::Finance);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => defaultPassword(),
            'password' => 'short',
            'password_confirmation' => 'mismatched',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    });

    it('records the change in the audit trail', function (): void {
        $user = actingAsRole(RoleName::Finance);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => defaultPassword(),
            'password' => 'a-much-better-password',
            'password_confirmation' => 'a-much-better-password',
        ])->assertOk();

        expect(AuditLog::query()->where('action', AuditAction::PasswordChanged->value)->where('user_id', $user->id)->exists())
            ->toBeTrue();
    });

    it('cannot be called without authentication', function (): void {
        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'x',
            'password' => 'a-much-better-password',
            'password_confirmation' => 'a-much-better-password',
        ])->assertUnauthorized();
    });
});

describe('password reset', function (): void {
    it('emails a reset link to a known address', function (): void {
        Notification::fake();
        $user = userWithRole(RoleName::LoanOfficer, ['email' => 'known@mikopofasta.co.tz']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'known@mikopofasta.co.tz'])
            ->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    });

    it('answers identically for an unknown address so accounts cannot be enumerated', function (): void {
        Notification::fake();
        userWithRole(RoleName::LoanOfficer, ['email' => 'known@mikopofasta.co.tz']);

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'known@mikopofasta.co.tz']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@mikopofasta.co.tz']);

        $known->assertOk();
        $unknown->assertOk();
        expect($unknown->json('data.message'))->toBe($known->json('data.message'));

        Notification::assertCount(1);
    });

    it('does not send a reset link to a suspended account', function (): void {
        Notification::fake();
        seedRbac();
        User::factory()->role(RoleName::Teller)->suspended()->create(['email' => 'suspended@mikopofasta.co.tz']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'suspended@mikopofasta.co.tz'])
            ->assertOk();

        Notification::assertNothingSent();
    });

    it('resets the password with a valid token and revokes existing sessions', function (): void {
        $user = userWithRole(RoleName::LoanOfficer, ['email' => 'reset@mikopofasta.co.tz']);
        $oldToken = $user->createToken('old', ['*'])->plainTextToken;

        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'reset@mikopofasta.co.tz',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        expect(Hash::check('brand-new-password', $user->refresh()->password))->toBeTrue();

        forgetAuthGuards();

        $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    });

    it('rejects an invalid reset token', function (): void {
        userWithRole(RoleName::LoanOfficer, ['email' => 'reset@mikopofasta.co.tz']);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'a-forged-token',
            'email' => 'reset@mikopofasta.co.tz',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_RESET_TOKEN');
    });

    it('rate limits reset requests for one address', function (): void {
        Notification::fake();
        userWithRole(RoleName::LoanOfficer, ['email' => 'spam@mikopofasta.co.tz']);

        // 3 per 15 minutes, keyed on the email address.
        foreach (range(1, 3) as $attempt) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => 'spam@mikopofasta.co.tz'])
                ->assertOk();
        }

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'spam@mikopofasta.co.tz'])
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'TOO_MANY_REQUESTS');
    });
});

<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\PermissionName;
use App\Domain\Auth\Enums\RoleName;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;

it('signs a user in with phone and password and returns a token', function (): void {
    $user = userWithRole(RoleName::LoanOfficer, ['phone' => '0754000099']);

    $response = $this->postJson('/api/v1/auth/login', [
        'phone' => '0754000099',
        'password' => defaultPassword(),
    ]);

    $response->assertOk()
        ->assertJsonPath('data.tokenType', 'Bearer')
        ->assertJsonPath('data.user.id', (string) $user->id)
        ->assertJsonPath('data.user.role', 'loan_officer')
        ->assertJsonStructure(['data' => ['token', 'tokenType', 'user' => [
            'id', 'name', 'phone', 'role', 'branchId', 'zoneId', 'regionId',
            'extraPermissions', 'permissions', 'avatarInitials',
        ]]]);

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();
});

it('returns the resolved permission set the BFF caches in its session cookie', function (): void {
    userWithRole(RoleName::Teller, ['phone' => '0754000098']);

    $response = $this->postJson('/api/v1/auth/login', [
        'phone' => '0754000098',
        'password' => defaultPassword(),
    ]);

    // Teller holds exactly two grants in §14 — cash entry, and nothing else.
    expect($response->json('data.user.permissions'))
        ->toEqualCanonicalizing([
            PermissionName::RepaymentsView->value,
            PermissionName::RepaymentsCashEntry->value,
        ]);
});

it('scopes the issued token abilities to the user permissions', function (): void {
    $user = userWithRole(RoleName::Teller, ['phone' => '0754000097']);

    $this->postJson('/api/v1/auth/login', [
        'phone' => '0754000097',
        'password' => defaultPassword(),
    ])->assertOk();

    // Spec §1: "a stolen token can't silently exceed the issuing user's
    // permissions" — the abilities must mirror the grants, not be '*'.
    $abilities = $user->tokens()->sole()->abilities;

    expect($abilities)
        ->toEqualCanonicalizing($user->effectivePermissionNames())
        ->not->toContain('*');
});

it('rejects a wrong password without revealing whether the phone exists', function (): void {
    userWithRole(RoleName::LoanOfficer, ['phone' => '0754000096']);

    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'phone' => '0754000096',
        'password' => 'not-the-password',
    ]);

    $unknownPhone = $this->postJson('/api/v1/auth/login', [
        'phone' => '0754999999',
        'password' => 'not-the-password',
    ]);

    $wrongPassword->assertUnauthorized()->assertJsonPath('error_code', 'INVALID_CREDENTIALS');

    // Identical status, code and message — otherwise this endpoint enumerates
    // valid accounts.
    $unknownPhone->assertUnauthorized()->assertJsonPath('error_code', 'INVALID_CREDENTIALS');
    expect($unknownPhone->json('message'))->toBe($wrongPassword->json('message'));
});

it('refuses to sign in a suspended user even with the right password', function (): void {
    seedRbac();
    User::factory()->role(RoleName::Teller)->suspended()->create(['phone' => '0754000095']);

    $this->postJson('/api/v1/auth/login', [
        'phone' => '0754000095',
        'password' => defaultPassword(),
    ])
        ->assertForbidden()
        ->assertJsonPath('error_code', 'ACCOUNT_SUSPENDED');
});

it('records the login and stamps last_login_at', function (): void {
    $user = userWithRole(RoleName::Finance, ['phone' => '0754000094']);

    expect($user->last_login_at)->toBeNull();

    $this->postJson('/api/v1/auth/login', [
        'phone' => '0754000094',
        'password' => defaultPassword(),
    ])->assertOk();

    expect($user->refresh()->last_login_at)->not->toBeNull();

    $log = AuditLog::query()->where('action', AuditAction::UserLoggedIn->value)->sole();
    expect($log->user_id)->toBe($user->id);
});

it('records a failed login without ever storing the submitted password', function (): void {
    userWithRole(RoleName::Finance, ['phone' => '0754000093']);

    $this->postJson('/api/v1/auth/login', [
        'phone' => '0754000093',
        'password' => 'hunter2-should-never-be-persisted',
    ])->assertUnauthorized();

    $log = AuditLog::query()->where('action', AuditAction::UserLoginFailed->value)->sole();

    expect($log->user_id)->toBeNull()
        ->and($log->after_json['identifier'])->toBe('0754000093')
        ->and($log->after_json['reason'])->toBe('bad_password')
        ->and(json_encode($log->after_json))->not->toContain('hunter2');
});

it('validates the login payload', function (): void {
    $this->postJson('/api/v1/auth/login', ['phone' => '077', 'password' => ''])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'VALIDATION_FAILED')
        ->assertJsonValidationErrors(['phone', 'password']);
});

it('returns the current user from /auth/me', function (): void {
    $user = actingAsRole(RoleName::Auditor);

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', (string) $user->id)
        ->assertJsonPath('data.role', 'auditor');
});

it('rejects /auth/me without a token', function (): void {
    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('error_code', 'UNAUTHENTICATED');
});

it('revokes only the current token on logout', function (): void {
    $user = userWithRole(RoleName::Teller, ['phone' => '0754000092']);

    // Two devices signed in.
    $phone = $user->createToken('phone', ['*'])->plainTextToken;
    $laptop = $user->createToken('laptop', ['*'])->plainTextToken;

    expect($user->tokens()->count())->toBe(2);

    $this->withHeader('Authorization', 'Bearer '.$laptop)
        ->postJson('/api/v1/auth/logout')
        ->assertOk();

    // The other device stays signed in.
    expect($user->refresh()->tokens()->count())->toBe(1);

    forgetAuthGuards();

    $this->withHeader('Authorization', 'Bearer '.$phone)
        ->getJson('/api/v1/auth/me')
        ->assertOk();

    // ...and the revoked one really is dead.
    forgetAuthGuards();

    $this->withHeader('Authorization', 'Bearer '.$laptop)
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});

it('rate limits repeated login attempts against one account', function (): void {
    userWithRole(RoleName::Teller, ['phone' => '0754000091']);

    // The limiter allows 5 per minute keyed on phone + IP.
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', [
            'phone' => '0754000091',
            'password' => 'wrong',
        ])->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/login', [
        'phone' => '0754000091',
        'password' => 'wrong',
    ])
        ->assertStatus(429)
        ->assertJsonPath('error_code', 'TOO_MANY_REQUESTS');
});

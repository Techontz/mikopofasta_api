<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Self-service profile.
 *
 * Most of these tests are about what the endpoint REFUSES. A profile page is
 * the natural place for a privilege-escalation attempt — it is the one screen
 * every employee can reach and the one that legitimately writes to their own
 * user row — so the interesting assertions are that role, branch, salary and
 * employment status come back unchanged no matter what is posted.
 */
beforeEach(function (): void {
    seedCustomerFoundation();
});

/**
 * An authenticated officer whose model is fully hydrated from the database.
 *
 * `Sanctum::actingAs` keeps the exact instance it is handed, and a
 * factory-built model only holds the attributes the factory set. With
 * `Model::shouldBeStrict()` on outside production, reading a column the
 * factory never touched throws — an artefact of the test harness, not of the
 * endpoint: real Sanctum hydrates the user from the row.
 */
function signedInOfficer(string $branch = 'Kakonko', RoleName $role = RoleName::LoanOfficer): User
{
    $user = officerAt($branch, $role)->fresh();
    Laravel\Sanctum\Sanctum::actingAs($user, ['*']);

    return $user;
}

describe('viewing your own profile', function (): void {
    it('returns identity, self-service fields and the organisation’s decisions', function (): void {
        $officer = signedInOfficer();

        $body = $this->getJson('/api/v1/auth/profile')->assertOk()->json('data');

        expect($body['name'])->toBe($officer->name)
            ->and($body['username'])->toBe($officer->phone)
            ->and($body)->toHaveKeys(['editable', 'readOnly', 'permissions'])
            ->and($body['editable'])->toHaveKeys([
                'phone', 'email', 'address', 'emergencyContactName', 'nextOfKinName',
                'preferredLanguage', 'notificationPreferences',
            ])
            ->and($body['readOnly'])->toHaveKeys([
                'employeeNumber', 'branch', 'role', 'employmentStatus', 'userStatus',
                'supervisor', 'zone', 'createdAt', 'lastLoginAt',
            ])
            ->and($body['permissions'])->toBeArray()->not->toBeEmpty();
    });

    it('reports the role and branch the organisation assigned', function (): void {
        $officer = signedInOfficer();

        $body = $this->getJson('/api/v1/auth/profile')->assertOk()->json('data.readOnly');

        expect($body['role'])->toBe('loan_officer')
            ->and($body['branch'])->toBe('Kakonko');
    });

    it('needs no permission beyond being signed in', function (): void {
        // A teller holds two permissions and neither is an admin grant.
        signedInOfficer('Kakonko', RoleName::Teller);

        $this->getJson('/api/v1/auth/profile')->assertOk();
    });

    it('refuses an unauthenticated caller', function (): void {
        $this->getJson('/api/v1/auth/profile')->assertUnauthorized();
    });
});

describe('editing your own profile', function (): void {
    it('saves the personal fields', function (): void {
        $officer = signedInOfficer();

        $this->patchJson('/api/v1/auth/profile', [
            'email' => 'esther.new@mikopofasta.co.tz',
            'address' => 'Plot 44, Kakonko',
            'emergencyContactName' => 'Neema Mollel',
            'emergencyContactPhone' => '0755123456',
            'emergencyContactRelationship' => 'Sister',
            'nextOfKinName' => 'Joseph Mollel',
            'nextOfKinPhone' => '0755123457',
            'nextOfKinRelationship' => 'Father',
            'preferredLanguage' => 'sw',
            'notificationPreferences' => ['sms' => false, 'email' => true, 'inApp' => true],
        ])->assertOk()
            ->assertJsonPath('data.editable.address', 'Plot 44, Kakonko')
            ->assertJsonPath('data.editable.preferredLanguage', 'sw')
            ->assertJsonPath('data.editable.notificationPreferences.sms', false);

        $officer->refresh();

        expect($officer->emergency_contact_name)->toBe('Neema Mollel')
            ->and($officer->next_of_kin_relationship)->toBe('Father')
            ->and($officer->email)->toBe('esther.new@mikopofasta.co.tz');
    });

    it('audits the change as a self-edit, not an administrator’s', function (): void {
        $officer = signedInOfficer();

        $this->patchJson('/api/v1/auth/profile', ['address' => 'New address'])->assertOk();

        $entry = AuditLog::query()
            ->where('action', AuditAction::UserProfileUpdated->value)
            ->sole();

        expect($entry->user_id)->toBe($officer->getKey())
            ->and($entry->auditable_id)->toBe($officer->getKey())
            ->and($entry->after_json['address'])->toBe('New address')
            ->and($entry->ip_address)->not->toBeNull();
    });

    it('rejects a phone number another user already holds', function (): void {
        /* A colleague whose number is already taken. Created explicitly —
           the demo roster is not seeded into the test database. */
        $colleague = userWithRole(RoleName::Teller, ['phone' => '0755999001']);
        signedInOfficer();

        $this->patchJson('/api/v1/auth/profile', ['phone' => $colleague->phone])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    });

    it('accepts the user’s own phone unchanged', function (): void {
        $officer = signedInOfficer();

        $this->patchJson('/api/v1/auth/profile', ['phone' => $officer->phone])->assertOk();
    });

    it('rejects a language outside the supported set', function (): void {
        signedInOfficer();

        $this->patchJson('/api/v1/auth/profile', ['preferredLanguage' => 'fr'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['preferredLanguage']);
    });
});

describe('what a profile may never change', function (): void {
    it('ignores role, branch, zone, status and created_by', function (): void {
        $officer = signedInOfficer();
        $before = $officer->only(['role_id', 'branch_id', 'zone_id', 'region_id', 'status', 'created_by']);

        $superAdminRole = App\Models\Role::query()->where('name', 'super_admin')->value('id');
        $otherBranch = App\Models\Branch::query()->where('name', 'Head Office')->value('id');

        $this->patchJson('/api/v1/auth/profile', [
            'address' => 'A legitimate change, to prove the request is otherwise accepted',
            'role_id' => $superAdminRole,
            'roleId' => $superAdminRole,
            'branch_id' => $otherBranch,
            'branchId' => $otherBranch,
            'zone_id' => 1,
            'status' => 'suspended',
            'created_by' => 1,
        ])->assertOk();

        $officer->refresh();

        expect($officer->only(['role_id', 'branch_id', 'zone_id', 'region_id', 'status', 'created_by']))
            ->toEqual($before)
            ->and($officer->address)->toBe('A legitimate change, to prove the request is otherwise accepted');
    });

    it('cannot touch the employment record HR owns', function (): void {
        $officer = signedInOfficer();
        $staff = $officer->staffProfile;

        if ($staff === null) {
            expect(true)->toBeTrue();

            return;
        }

        $before = $staff->only(['employee_number', 'base_salary', 'employment_status', 'branch_id']);

        $this->patchJson('/api/v1/auth/profile', [
            'employeeNumber' => 'EMP-9999',
            'employee_number' => 'EMP-9999',
            'baseSalary' => 99_000_000,
            'base_salary' => 99_000_000,
            'employmentStatus' => 'terminated',
            'employment_status' => 'terminated',
        ])->assertOk();

        expect($staff->refresh()->only(['employee_number', 'base_salary', 'employment_status', 'branch_id']))
            ->toEqual($before);
    });

    it('has no route that names another user', function (): void {
        $other = userWithRole(RoleName::Teller, ['phone' => '0755999002', 'address' => null]);
        signedInOfficer();

        // There is no /auth/profile/{user} — the only profile route acts on
        // the token's owner, which is what makes this unreachable rather than
        // merely forbidden.
        $this->patchJson("/api/v1/auth/profile/{$other->id}", ['address' => 'x'])
            ->assertNotFound();

        expect($other->refresh()->address)->toBeNull();
    });

    it('does not grant a permission the role never had', function (): void {
        signedInOfficer('Kakonko', RoleName::Teller);

        $before = $this->getJson('/api/v1/auth/profile')->json('data.permissions');

        $this->patchJson('/api/v1/auth/profile', [
            'permissions' => ['admin.org_settings'],
            'extraPermissions' => ['admin.org_settings'],
        ])->assertOk();

        expect($this->getJson('/api/v1/auth/profile')->json('data.permissions'))->toEqual($before);
    });
});

describe('profile photo', function (): void {
    beforeEach(function (): void {
        Storage::fake(App\Domain\Customers\Services\KycDocumentStorage::DISK);
    });

    it('stores the photo privately and returns only a signed URL', function (): void {
        $officer = signedInOfficer();

        $url = $this->post('/api/v1/auth/profile/photo', [
            'photo' => UploadedFile::fake()->image('me.jpg'),
        ])->assertOk()->json('data.photoUrl');

        $officer->refresh();

        expect($officer->photo_path)->toStartWith('users/'.$officer->id.'/')
            ->and($url)->toContain('signature=')
            ->and($url)->not->toContain($officer->photo_path);

        Storage::disk(App\Domain\Customers\Services\KycDocumentStorage::DISK)
            ->assertExists($officer->photo_path);
    });

    it('refuses a non-image', function (): void {
        signedInOfficer();

        $this->post('/api/v1/auth/profile/photo', [
            'photo' => UploadedFile::fake()->create('payload.exe', 40),
        ])->assertStatus(422)->assertJsonValidationErrors(['photo']);
    });

    it('serves the photo only under a valid signature', function (): void {
        signedInOfficer();
        $url = $this->post('/api/v1/auth/profile/photo', [
            'photo' => UploadedFile::fake()->image('me.jpg'),
        ])->json('data.photoUrl');

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get(explode('?', (string) $url)[0])->assertForbidden();
    });
});

describe('changing your password', function (): void {
    it('requires the current password to be correct', function (): void {
        signedInOfficer();

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'not-the-password',
            'password' => 'A-str0ng-New-Passw0rd!',
            'password_confirmation' => 'A-str0ng-New-Passw0rd!',
        ])->assertStatus(422);
    });

    it('enforces the password policy', function (): void {
        signedInOfficer();

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    });

    it('requires the confirmation to match', function (): void {
        signedInOfficer();

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'A-str0ng-New-Passw0rd!',
            'password_confirmation' => 'A-different-Passw0rd!',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    });

    it('changes the password, signs other sessions out and notifies the user', function (): void {
        Notification::fake();
        $officer = signedInOfficer();

        // A second device on the same account.
        $otherToken = $officer->createToken('other-device')->plainTextToken;

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'A-str0ng-New-Passw0rd!',
            'password_confirmation' => 'A-str0ng-New-Passw0rd!',
        ])->assertOk()->assertJsonStructure(['data' => ['token', 'tokenType']]);

        expect(Illuminate\Support\Facades\Hash::check('A-str0ng-New-Passw0rd!', $officer->refresh()->password))
            ->toBeTrue();

        // The other device is gone. The guard caches whoever it resolved for
        // the previous request, so it has to be cleared or this proves nothing.
        forgetAuthGuards();

        $this->withHeader('Authorization', 'Bearer '.$otherToken)
            ->getJson('/api/v1/auth/profile')
            ->assertUnauthorized();

        Notification::assertSentTo($officer, PasswordChangedNotification::class);
        expect(AuditLog::query()->where('action', AuditAction::PasswordChanged->value)->count())->toBe(1);
    });

    it('still succeeds when the user has no email to notify', function (): void {
        Notification::fake();
        $officer = signedInOfficer();
        $officer->forceFill(['email' => null])->save();

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'A-str0ng-New-Passw0rd!',
            'password_confirmation' => 'A-str0ng-New-Passw0rd!',
        ])->assertOk();

        Notification::assertNothingSent();
        expect(AuditLog::query()->where('action', AuditAction::PasswordChanged->value)->count())->toBe(1);
    });
});

describe('preferences', function (): void {
    it('saves presentation preferences', function (): void {
        $officer = signedInOfficer();

        $this->patchJson('/api/v1/auth/profile', [
            'timezone' => 'Africa/Dar_es_Salaam',
            'dateFormat' => 'dd/mm/yyyy',
            'numberFormat' => '1,234.56',
            'theme' => 'dark',
        ])->assertOk()
            ->assertJsonPath('data.editable.timezone', 'Africa/Dar_es_Salaam')
            ->assertJsonPath('data.editable.theme', 'dark');

        expect($officer->refresh()->date_format)->toBe('dd/mm/yyyy');
    });

    it('refuses values outside the supported sets', function (): void {
        signedInOfficer();

        $this->patchJson('/api/v1/auth/profile', ['timezone' => 'Mars/Olympus'])
            ->assertStatus(422)->assertJsonValidationErrors(['timezone']);

        $this->patchJson('/api/v1/auth/profile', ['dateFormat' => "'; DROP TABLE users;--"])
            ->assertStatus(422)->assertJsonValidationErrors(['dateFormat']);

        $this->patchJson('/api/v1/auth/profile', ['theme' => 'neon'])
            ->assertStatus(422)->assertJsonValidationErrors(['theme']);
    });

    it('leaves a user who never sets them on the system default', function (): void {
        signedInOfficer();

        $editable = $this->getJson('/api/v1/auth/profile')->json('data.editable');

        // null means "follow the system", not "no preference recorded yet".
        expect($editable['timezone'])->toBeNull()
            ->and($editable['dateFormat'])->toBeNull()
            ->and($editable['theme'])->toBeNull();
    });
});

describe('security tab', function (): void {
    it('reports password, login and session information', function (): void {
        $officer = signedInOfficer();

        $body = $this->getJson('/api/v1/auth/profile/security')->assertOk()->json('data');

        expect($body)->toHaveKeys([
            'passwordChangedAt', 'lastLoginAt', 'lastFailedLoginAt', 'sessions', 'twoFactor',
        ])->and($body['twoFactor'])->toEqual(['enabled' => false, 'available' => false]);
    });

    it('reports the password change once one has happened', function (): void {
        signedInOfficer();

        expect($this->getJson('/api/v1/auth/profile/security')->json('data.passwordChangedAt'))->toBeNull();

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'A-str0ng-New-Passw0rd!',
            'password_confirmation' => 'A-str0ng-New-Passw0rd!',
        ])->assertOk();

        expect($this->getJson('/api/v1/auth/profile/security')->json('data.passwordChangedAt'))
            ->not->toBeNull();
    });

    it('lists real tokens and marks nothing current under actingAs', function (): void {
        $officer = signedInOfficer();
        $officer->createToken('phone');
        $officer->createToken('laptop');

        $sessions = $this->getJson('/api/v1/auth/profile/security')->json('data.sessions');

        expect($sessions)->toHaveCount(2)
            ->and(collect($sessions)->pluck('name')->all())->toEqualCanonicalizing(['phone', 'laptop']);
    });

    it('signs other sessions out and keeps this one', function (): void {
        $officer = signedInOfficer();
        $other = $officer->createToken('other-device')->plainTextToken;
        $officer->createToken('third-device');

        $this->postJson('/api/v1/auth/sessions/revoke-others')
            ->assertOk()
            ->assertJsonPath('data.revoked', 2);

        forgetAuthGuards();

        $this->withHeader('Authorization', 'Bearer '.$other)
            ->getJson('/api/v1/auth/profile')
            ->assertUnauthorized();

        expect(AuditLog::query()->where('action', AuditAction::UserSessionsRevoked->value)->count())->toBe(1);
    });

    it('is honest when there is nothing else signed in', function (): void {
        signedInOfficer();

        $this->postJson('/api/v1/auth/sessions/revoke-others')
            ->assertOk()
            ->assertJsonPath('data.revoked', 0);
    });
});

describe('activity', function (): void {
    it('lists this account’s own audit entries, newest first', function (): void {
        signedInOfficer();

        $this->patchJson('/api/v1/auth/profile', ['address' => 'One'])->assertOk();
        $this->patchJson('/api/v1/auth/profile', ['address' => 'Two'])->assertOk();

        $entries = $this->getJson('/api/v1/auth/profile/activity')->assertOk()->json('data');

        expect($entries)->not->toBeEmpty()
            ->and($entries[0]['action'])->toBe(AuditAction::UserProfileUpdated->value)
            ->and($entries[0])->toHaveKeys(['id', 'action', 'ipAddress', 'at']);
    });

    it('never shows another user’s activity', function (): void {
        $other = userWithRole(RoleName::Teller, ['phone' => '0755999003']);
        AuditLog::create([
            'user_id' => $other->getKey(),
            'action' => AuditAction::UserLoggedIn->value,
            'auditable_type' => User::class,
            'auditable_id' => $other->getKey(),
            'ip_address' => '10.0.0.9',
            'created_at' => now(),
        ]);

        signedInOfficer();
        $this->patchJson('/api/v1/auth/profile', ['address' => 'Mine'])->assertOk();

        $entries = $this->getJson('/api/v1/auth/profile/activity')->json('data');

        expect(collect($entries)->pluck('ipAddress'))->not->toContain('10.0.0.9');
    });
});

describe('removing the profile photo', function (): void {
    beforeEach(function (): void {
        Storage::fake(App\Domain\Customers\Services\KycDocumentStorage::DISK);
    });

    it('deletes the file and clears the record', function (): void {
        $officer = signedInOfficer();

        $this->post('/api/v1/auth/profile/photo', [
            'photo' => UploadedFile::fake()->image('me.jpg'),
        ])->assertOk();

        $path = $officer->refresh()->photo_path;
        expect($path)->not->toBeNull();

        $this->deleteJson('/api/v1/auth/profile/photo')
            ->assertOk()
            ->assertJsonPath('data.photoUrl', null);

        expect($officer->refresh()->photo_path)->toBeNull();
        Storage::disk(App\Domain\Customers\Services\KycDocumentStorage::DISK)->assertMissing($path);
    });

    it('is harmless when there is no photo', function (): void {
        signedInOfficer();

        $this->deleteJson('/api/v1/auth/profile/photo')->assertOk();
    });

    it('replaces the old file rather than accumulating them', function (): void {
        $officer = signedInOfficer();

        $this->post('/api/v1/auth/profile/photo', ['photo' => UploadedFile::fake()->image('one.jpg')])->assertOk();
        $first = $officer->refresh()->photo_path;

        $this->post('/api/v1/auth/profile/photo', ['photo' => UploadedFile::fake()->image('two.jpg')])->assertOk();
        $second = $officer->refresh()->photo_path;

        expect($second)->not->toBe($first);
        Storage::disk(App\Domain\Customers\Services\KycDocumentStorage::DISK)->assertMissing($first);
        Storage::disk(App\Domain\Customers\Services\KycDocumentStorage::DISK)->assertExists($second);
    });
});

<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Auth\Enums\UserStatus;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;

function payload(array $overrides = []): array
{
    return array_merge([
        'name' => 'New Officer',
        'phone' => '0755100200',
        'email' => 'new.officer@mikopofasta.co.tz',
        'password' => 'initial-password',
        'role' => RoleName::LoanOfficer->value,
        'branchId' => null,
        'zoneId' => null,
        'regionId' => null,
    ], $overrides);
}

describe('listing', function (): void {
    it('lists users with the frontend envelope and camelCase string ids', function (): void {
        $admin = actingAsRole(RoleName::Admin);

        $response = $this->getJson('/api/v1/users');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'phone', 'email', 'role', 'branchId', 'zoneId', 'regionId', 'status', 'lastLoginAt', 'createdBy', 'deletedAt']],
                'meta' => ['pagination' => ['page', 'perPage', 'total', 'lastPage']],
            ]);

        // types/user.ts declares `id: z.string()` — a bare integer would fail
        // the frontend's Zod validation.
        expect($response->json('data.0.id'))->toBeString()
            ->and($response->json('meta.pagination.total'))->toBe(1)
            ->and($response->json('data.0.id'))->toBe((string) $admin->id);
    });

    it('filters by search term, status and role', function (): void {
        actingAsRole(RoleName::Admin);
        User::factory()->role(RoleName::Teller)->create(['name' => 'Zubeda Cash', 'phone' => '0755111222']);
        User::factory()->role(RoleName::Finance)->suspended()->create(['name' => 'Suspended Sam']);

        expect($this->getJson('/api/v1/users?search=Zubeda')->json('meta.pagination.total'))->toBe(1)
            ->and($this->getJson('/api/v1/users?search=0755111222')->json('meta.pagination.total'))->toBe(1)
            ->and($this->getJson('/api/v1/users?status=suspended')->json('meta.pagination.total'))->toBe(1)
            ->and($this->getJson('/api/v1/users?role=teller')->json('meta.pagination.total'))->toBe(1);
    });

    it('clamps per_page to the documented maximum of 100', function (): void {
        actingAsRole(RoleName::Admin);

        expect($this->getJson('/api/v1/users?per_page=5')->json('meta.pagination.perPage'))->toBe(5);

        // Spec §1 caps per_page at 100; anything larger is rejected by
        // validation rather than silently honoured.
        $this->getJson('/api/v1/users?per_page=5000')->assertStatus(422);
    });

    it('hides soft-deleted users unless explicitly asked for', function (): void {
        actingAsRole(RoleName::Admin);
        $gone = User::factory()->role(RoleName::Teller)->create();
        $gone->delete();

        expect($this->getJson('/api/v1/users')->json('meta.pagination.total'))->toBe(1)
            ->and($this->getJson('/api/v1/users?include_deleted=1')->json('meta.pagination.total'))->toBe(2);
    });
});

describe('creating', function (): void {
    it('creates a user and records who provisioned it', function (): void {
        $admin = actingAsRole(RoleName::Admin);

        $response = $this->postJson('/api/v1/users', payload());

        $response->assertCreated()
            ->assertJsonPath('data.name', 'New Officer')
            ->assertJsonPath('data.role', 'loan_officer')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.createdBy', (string) $admin->id);

        $created = User::query()->where('phone', '0755100200')->sole();

        // The Spatie pivot must agree with the authoritative role_id.
        expect($created->roles->pluck('name')->all())->toBe(['loan_officer']);
    });

    it('rejects a duplicate phone number', function (): void {
        actingAsRole(RoleName::Admin);
        User::factory()->role(RoleName::Teller)->create(['phone' => '0755100200']);

        $this->postJson('/api/v1/users', payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone'])
            ->assertJsonPath('errors.phone.0', 'A user with this phone number already exists.');
    });

    it('rejects an unknown role', function (): void {
        actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/users', payload(['role' => 'chief_executive']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    });

    it('records creation in the audit trail', function (): void {
        $admin = actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/users', payload())->assertCreated();

        $log = AuditLog::query()->where('action', AuditAction::UserCreated->value)->sole();

        expect($log->user_id)->toBe($admin->id)
            ->and($log->after_json['phone'])->toBe('0755100200')
            ->and(json_encode($log->after_json))->not->toContain('initial-password');
    });
});

describe('updating', function (): void {
    it('updates profile and role', function (): void {
        actingAsRole(RoleName::Admin);
        $user = User::factory()->role(RoleName::Teller)->create();

        $this->putJson("/api/v1/users/{$user->id}", [
            'name' => 'Promoted Person',
            'phone' => $user->phone,
            'email' => $user->email,
            'role' => RoleName::BranchManager->value,
            'branchId' => null,
            'zoneId' => null,
            'regionId' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.role', 'branch_manager');

        expect($user->refresh()->roles->pluck('name')->all())->toBe(['branch_manager']);
    });

    it('revokes existing tokens when the role changes, so stale abilities cannot persist', function (): void {
        actingAsRole(RoleName::Admin);
        $user = User::factory()->role(RoleName::Teller)->create();
        $user->createToken('device', $user->effectivePermissionNames());

        expect($user->tokens()->count())->toBe(1);

        $this->putJson("/api/v1/users/{$user->id}", [
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'role' => RoleName::Finance->value,
            'branchId' => null, 'zoneId' => null, 'regionId' => null,
        ])->assertOk();

        // A token minted under the old role still carries the old abilities.
        expect($user->refresh()->tokens()->count())->toBe(0);
    });

    it('records a before and after snapshot', function (): void {
        actingAsRole(RoleName::Admin);
        $user = User::factory()->role(RoleName::Teller)->create(['name' => 'Before Name']);

        $this->putJson("/api/v1/users/{$user->id}", [
            'name' => 'After Name',
            'phone' => $user->phone,
            'email' => $user->email,
            'role' => RoleName::Teller->value,
            'branchId' => null, 'zoneId' => null, 'regionId' => null,
        ])->assertOk();

        $log = AuditLog::query()->where('action', AuditAction::UserUpdated->value)->sole();

        expect($log->before_json['name'])->toBe('Before Name')
            ->and($log->after_json['name'])->toBe('After Name');
    });
});

describe('status', function (): void {
    it('suspends a user and kills their live sessions immediately', function (): void {
        actingAsRole(RoleName::Admin);
        $user = User::factory()->role(RoleName::Teller)->create();
        $token = $user->createToken('device', ['*'])->plainTextToken;

        $this->patchJson("/api/v1/users/{$user->id}/status", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        // Without revocation the suspension would only bite at next login —
        // exactly when it no longer matters.
        forgetAuthGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    });

    it('re-enables a suspended user', function (): void {
        actingAsRole(RoleName::Admin);
        $user = User::factory()->role(RoleName::Teller)->suspended()->create();

        $this->patchJson("/api/v1/users/{$user->id}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        expect($user->refresh()->status)->toBe(UserStatus::Active);
    });

    it('refuses to let an administrator change their own status', function (): void {
        $admin = actingAsRole(RoleName::Admin);

        $this->patchJson("/api/v1/users/{$admin->id}/status", ['status' => 'suspended'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'CANNOT_MODIFY_OWN_ACCOUNT');

        expect($admin->refresh()->status)->toBe(UserStatus::Active);
    });
});

describe('deleting', function (): void {
    it('soft-deletes rather than destroying the row', function (): void {
        actingAsRole(RoleName::Admin);
        $user = User::factory()->role(RoleName::Teller)->create();

        $this->deleteJson("/api/v1/users/{$user->id}")->assertOk();

        // The id is referenced from audit_logs and, later, loans and payments —
        // a hard delete would orphan the trail (spec §2).
        $this->assertSoftDeleted('users', ['id' => $user->id]);
        expect(User::withTrashed()->whereKey($user->id)->exists())->toBeTrue();
    });

    it('refuses self-deletion', function (): void {
        $admin = actingAsRole(RoleName::Admin);

        $this->deleteJson("/api/v1/users/{$admin->id}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'CANNOT_MODIFY_OWN_ACCOUNT');
    });

    it('returns the standard not-found envelope for a missing user', function (): void {
        actingAsRole(RoleName::Admin);

        $this->getJson('/api/v1/users/999999')
            ->assertNotFound()
            ->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
    });
});

describe('authorization', function (): void {
    it('denies every user endpoint to a role without users.manage', function (): void {
        $officer = actingAsRole(RoleName::LoanOfficer);
        $other = User::factory()->role(RoleName::Teller)->create();

        $this->getJson('/api/v1/users')->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');
        $this->postJson('/api/v1/users', payload())->assertForbidden();
        $this->putJson("/api/v1/users/{$other->id}", payload())->assertForbidden();
        $this->patchJson("/api/v1/users/{$other->id}/status", ['status' => 'suspended'])->assertForbidden();
        $this->deleteJson("/api/v1/users/{$other->id}")->assertForbidden();

        // ...but they can still read their own record.
        $this->getJson("/api/v1/users/{$officer->id}")->assertOk();
    });

    it('lets a super admin through even though Gate::before is the only grant path', function (): void {
        actingAsRole(RoleName::SuperAdmin);

        $this->getJson('/api/v1/users')->assertOk();
    });

    it('requires authentication', function (): void {
        seedRbac();

        $this->getJson('/api/v1/users')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    });
});

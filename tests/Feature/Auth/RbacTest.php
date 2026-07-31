<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\PermissionName;
use App\Domain\Auth\Enums\RoleName;
use App\Domain\Auth\Support\RolePermissionMatrix;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

describe('seed', function (): void {
    it('seeds exactly the roles and permissions defined in §14', function (): void {
        seedRbac();

        expect(Role::query()->pluck('name')->sort()->values()->all())
            ->toEqualCanonicalizing(RoleName::values())
            ->and(Permission::query()->pluck('name')->all())
            ->toEqualCanonicalizing(PermissionName::values())
            ->and(Permission::count())->toBe(29);
    });

    it('grants each role exactly the permissions the frontend matrix declares', function (): void {
        seedRbac();

        foreach (RoleName::cases() as $roleName) {
            $granted = Role::query()->where('name', $roleName->value)
                ->sole()
                ->permissions->pluck('name')->all();

            expect($granted)->toEqualCanonicalizing(
                RolePermissionMatrix::for($roleName),
                "Role [{$roleName->value}] grants drifted from the §14 matrix",
            );
        }
    });

    it('gives super admin every permission and teller only two', function (): void {
        seedRbac();

        expect(Role::query()->where('name', 'super_admin')->sole()->permissions)->toHaveCount(29)
            ->and(Role::query()->where('name', 'teller')->sole()->permissions->pluck('name')->all())
            ->toEqualCanonicalizing([
                PermissionName::RepaymentsView->value,
                PermissionName::RepaymentsCashEntry->value,
            ]);
    });

    it('never grants cross-branch loan review to any role by default', function (): void {
        seedRbac();

        // Spec §13/§14 Decision 1: this is always an explicit per-user grant.
        foreach (RoleName::cases() as $roleName) {
            if ($roleName === RoleName::SuperAdmin) {
                continue; // holds everything by definition
            }

            expect(RolePermissionMatrix::for($roleName))
                ->not->toContain(PermissionName::LoansReviewCrossBranch->value);
        }
    });

    it('keeps the auditor strictly read-only', function (): void {
        seedRbac();

        $auditor = Role::query()->where('name', 'auditor')->sole()->permissions->pluck('name');

        foreach (['manage', 'approve', 'finalize', 'reverse', 'create', 'disburse'] as $mutating) {
            expect($auditor->filter(fn (string $p): bool => str_contains($p, $mutating)))
                ->toBeEmpty("Auditor must hold no '{$mutating}' permission");
        }
    });
});

describe('per-user grants', function (): void {
    it('layers extra permissions on top of the role without changing the role', function (): void {
        $user = userWithRole(RoleName::ZoneManager);

        expect($user->hasPermission(PermissionName::LoansReviewCrossBranch))->toBeFalse();

        $user->givePermissionTo(PermissionName::LoansReviewCrossBranch->value);

        expect($user->fresh()->hasPermission(PermissionName::LoansReviewCrossBranch))->toBeTrue()
            ->and($user->fresh()->extraPermissionNames())->toBe([PermissionName::LoansReviewCrossBranch->value])
            // The role itself is untouched — the grant is on the person.
            ->and(RolePermissionMatrix::for(RoleName::ZoneManager))
            ->not->toContain(PermissionName::LoansReviewCrossBranch->value);
    });
});

describe('roles endpoint', function (): void {
    it('lists roles with their live grants for a user holding roles.view', function (): void {
        actingAsRole(RoleName::Admin);

        $response = $this->getJson('/api/v1/roles');

        $response->assertOk()
            ->assertJsonCount(11, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'label', 'description', 'editable', 'permissions']]]);

        $superAdmin = collect($response->json('data'))->firstWhere('name', 'super_admin');
        expect($superAdmin['editable'])->toBeFalse()
            ->and($superAdmin['permissions'])->toHaveCount(29);
    });

    it('denies the roles list to a role without roles.view', function (): void {
        actingAsRole(RoleName::Teller);

        $this->getJson('/api/v1/roles')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');
    });

    it('exposes the permission catalogue with its display groups', function (): void {
        actingAsRole(RoleName::Admin);

        $response = $this->getJson('/api/v1/permissions');

        $response->assertOk()
            ->assertJsonCount(29, 'data')
            ->assertJsonStructure(['data' => [['name', 'label', 'group']], 'meta' => ['groups']]);

        expect(collect($response->json('meta.groups'))->pluck('label')->all())
            ->toBe(PermissionName::groupOrder());
    });
});

describe('permission matrix', function (): void {
    it('lets a super admin replace a role permission set', function (): void {
        $superAdmin = actingAsRole(RoleName::SuperAdmin);
        $teller = Role::query()->where('name', 'teller')->sole();

        $next = [
            PermissionName::RepaymentsView->value,
            PermissionName::RepaymentsCashEntry->value,
            PermissionName::ReportsView->value,
        ];

        $this->putJson("/api/v1/roles/{$teller->id}/permissions", ['permissions' => $next])
            ->assertOk()
            ->assertJsonPath('data.name', 'teller');

        expect($teller->fresh()->permissions->pluck('name')->all())->toEqualCanonicalizing($next);
    });

    it('allows a role to be stripped of every permission', function (): void {
        actingAsRole(RoleName::SuperAdmin);
        $teller = Role::query()->where('name', 'teller')->sole();

        // `present` not `required` — an empty array must be expressible,
        // otherwise revoking the last permission is impossible.
        $this->putJson("/api/v1/roles/{$teller->id}/permissions", ['permissions' => []])
            ->assertOk();

        expect($teller->fresh()->permissions)->toBeEmpty();
    });

    it('refuses to edit super admin', function (): void {
        actingAsRole(RoleName::SuperAdmin);
        $superAdminRole = Role::query()->where('name', 'super_admin')->sole();

        $this->putJson("/api/v1/roles/{$superAdminRole->id}/permissions", ['permissions' => []])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'ROLE_NOT_EDITABLE');

        expect($superAdminRole->fresh()->permissions)->toHaveCount(29);
    });

    it('denies matrix edits to an admin, who may look but not change', function (): void {
        actingAsRole(RoleName::Admin);
        $teller = Role::query()->where('name', 'teller')->sole();

        // §14 splits roles.view from roles.manage precisely so an
        // administrator cannot grant themselves ledger-reversal approval.
        $this->putJson("/api/v1/roles/{$teller->id}/permissions", ['permissions' => []])
            ->assertForbidden();

        expect($teller->fresh()->permissions)->toHaveCount(2);
    });

    it('rejects an unknown permission string', function (): void {
        actingAsRole(RoleName::SuperAdmin);
        $teller = Role::query()->where('name', 'teller')->sole();

        $this->putJson("/api/v1/roles/{$teller->id}/permissions", [
            'permissions' => ['loans.do_whatever_i_want'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['permissions.0']);
    });

    it('records the before and after grant sets', function (): void {
        $superAdmin = actingAsRole(RoleName::SuperAdmin);
        $teller = Role::query()->where('name', 'teller')->sole();

        $this->putJson("/api/v1/roles/{$teller->id}/permissions", [
            'permissions' => [PermissionName::RepaymentsView->value],
        ])->assertOk();

        $log = AuditLog::query()->where('action', AuditAction::RolePermissionsUpdated->value)->sole();

        expect($log->user_id)->toBe($superAdmin->id)
            ->and($log->before_json['permissions'])->toHaveCount(2)
            ->and($log->after_json['permissions'])->toBe([PermissionName::RepaymentsView->value]);
    });

    it('takes effect immediately for a user holding that role', function (): void {
        actingAsRole(RoleName::SuperAdmin);
        $teller = Role::query()->where('name', 'teller')->sole();
        $tellerUser = User::factory()->role(RoleName::Teller)->create();

        expect($tellerUser->hasPermission(PermissionName::ReportsView))->toBeFalse();

        $this->putJson("/api/v1/roles/{$teller->id}/permissions", [
            'permissions' => [PermissionName::ReportsView->value],
        ])->assertOk();

        // Authorization reads role_has_permissions, never the seed matrix —
        // otherwise the matrix screen would be cosmetic.
        app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        expect($tellerUser->fresh()->hasPermission(PermissionName::ReportsView))->toBeTrue();
    });
});

describe('role pivot integrity', function (): void {
    it('keeps the spatie pivot in sync with the authoritative role_id column', function (): void {
        $user = userWithRole(RoleName::Teller);

        expect($user->roles->pluck('name')->all())->toBe(['teller']);

        $financeId = Role::query()->where('name', 'finance')->value('id');
        $user->update(['role_id' => $financeId]);

        // There is no code path that writes model_has_roles directly, so this
        // is the only thing keeping the two representations honest.
        expect($user->fresh()->roles->pluck('name')->all())->toBe(['finance'])
            ->and($user->fresh()->roles)->toHaveCount(1);
    });
});

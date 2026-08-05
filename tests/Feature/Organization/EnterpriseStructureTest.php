<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\PermissionName;
use App\Domain\Auth\Enums\RoleName;
use App\Domain\Auth\Enums\UserStatus;
use App\Domain\Ledger\Services\SystemActor;
use App\Domain\Organization\Enums\OrganizationTier;
use App\Domain\Organization\Services\OrganizationHierarchy;
use App\Exceptions\ConfigurationException;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Models\Zone;
use Database\Seeders\SystemUserSeeder;
use Illuminate\Database\QueryException;

/**
 * The enterprise structure.
 *
 *     SUPER ADMIN  →  HEAD OFFICE  →  ZONES  →  BRANCHES
 *
 * The tests that matter here are the ones about the SEAM: a user's tier is read
 * from where they are posted, and it must agree with what BranchScope lets them
 * see. A hierarchy that says "Head Office" while the queries return one
 * branch's data is worse than no hierarchy at all.
 */
beforeEach(function (): void {
    seedOrganization();
});

function hierarchy(): OrganizationHierarchy
{
    return app(OrganizationHierarchy::class);
}

function headOfficeBranch(): Branch
{
    $branch = Branch::query()->where('is_head_office', true)->first();

    if ($branch === null) {
        $branch = Branch::query()->firstOrFail();
        $branch->update(['is_head_office' => true]);
    }

    return $branch;
}

describe('tiers', function (): void {
    it('places a super admin above the offices', function (): void {
        $user = userWithRole(RoleName::SuperAdmin);

        expect(hierarchy()->tierFor($user))->toBe(OrganizationTier::SuperAdmin);
    });

    it('places anyone posted to the head office at Head Office, whatever their role', function (): void {
        /*
         * The client's list names "Head Office Tellers", "Head Office
         * Accountant" and so on. Those are the ordinary roles done at the
         * centre — the office comes from the posting, not from a separate role.
         */
        $ho = headOfficeBranch();

        foreach ([RoleName::Teller, RoleName::Accountant, RoleName::Cashier, RoleName::CustomerCare] as $role) {
            $user = User::factory()->role($role)->create(['branch_id' => $ho->getKey()]);

            expect(hierarchy()->tierFor($user))->toBe(OrganizationTier::HeadOffice);
        }
    });

    it('places the same roles at Branch when posted to a branch', function (): void {
        $branch = Branch::query()->where('is_head_office', false)->firstOrFail();

        foreach ([RoleName::Teller, RoleName::Accountant, RoleName::Cashier, RoleName::CustomerCare] as $role) {
            $user = User::factory()->role($role)->create(['branch_id' => $branch->getKey()]);

            expect(hierarchy()->tierFor($user))->toBe(OrganizationTier::Branch);
        }
    });

    it('places a zone-pinned user at Zone even though they see many branches', function (): void {
        $zone = Zone::query()->firstOrFail();
        $user = User::factory()->role(RoleName::ZoneManager)->create([
            'branch_id' => null,
            'zone_id' => $zone->getKey(),
        ]);

        expect(hierarchy()->tierFor($user))->toBe(OrganizationTier::Zone);
    });

    it('places the automation outside the offices entirely', function (): void {
        $system = User::query()->where('phone', SystemUserSeeder::PHONE)->sole();

        expect(hierarchy()->tierFor($system))->toBe(OrganizationTier::System);
    });
});

describe('the tier agrees with what the user can actually see', function (): void {
    it('gives a head office user the whole book', function (): void {
        $ho = headOfficeBranch();
        $manager = User::factory()->role(RoleName::HeadOfficeManager)->create(['branch_id' => $ho->getKey()]);

        $scope = app(App\Domain\Organization\Services\BranchScope::class);

        expect(hierarchy()->tierFor($manager))->toBe(OrganizationTier::HeadOffice)
            ->and($scope->visibleBranchIds($manager))->toHaveCount(Branch::query()->count())
            /*
             * Seeing every branch is not authority to act on every branch.
             * §13/§14 keep `loans.review_cross_branch` an explicit per-user
             * grant, and the most senior operational role is precisely the one
             * where that distinction would otherwise be quietly lost.
             */
            ->and($manager->hasPermission(PermissionName::LoansReviewCrossBranch))->toBeFalse();
    });

    it('gives a zone manager only their zone', function (): void {
        $zone = Zone::query()->firstOrFail();
        $inZone = Branch::query()->where('zone_id', $zone->getKey())->count();

        $manager = User::factory()->role(RoleName::ZoneManager)->create([
            'branch_id' => null,
            'zone_id' => $zone->getKey(),
        ]);

        $scope = app(App\Domain\Organization\Services\BranchScope::class);

        /*
         * The tier and the scope are computed by two different services from the
         * same facts. This is the assertion that keeps them honest — a Zone
         * Manager who was told they were Head Office would be shown a dashboard
         * for a book the API then refuses to give them.
         */
        expect(hierarchy()->tierFor($manager))->toBe(OrganizationTier::Zone)
            ->and($scope->visibleBranchIds($manager))->toHaveCount($inZone)
            ->and($inZone)->toBeLessThan(Branch::query()->count());
    });

    it('gives a branch user their branch and its sub-branches', function (): void {
        $branch = Branch::query()->where('is_head_office', false)->firstOrFail();
        $officer = User::factory()->role(RoleName::LoanOfficer)->create(['branch_id' => $branch->getKey()]);

        $scope = app(App\Domain\Organization\Services\BranchScope::class);

        expect(hierarchy()->tierFor($officer))->toBe(OrganizationTier::Branch)
            ->and($scope->visibleBranchIds($officer))->toBe($branch->selfAndDescendantIds());
    });
});

describe('reporting relationships', function (): void {
    it('reports a branch up through its zone to the head office', function (): void {
        headOfficeBranch();

        $branch = Branch::query()
            ->where('is_head_office', false)
            ->whereNotNull('zone_id')
            ->firstOrFail();

        $line = collect(hierarchy()->reportingLineFor($branch))->pluck('tier')->all();

        expect($line)->toContain(OrganizationTier::Zone->value)
            ->and($line)->toContain(OrganizationTier::HeadOffice->value);
    });

    it('does not report the head office to itself', function (): void {
        $ho = headOfficeBranch();

        expect(collect(hierarchy()->reportingLineFor($ho))->pluck('id')->all())
            ->not->toContain((string) $ho->getKey());
    });
});

describe('the structure endpoint', function (): void {
    it('needs the organisation grant', function (): void {
        actingAsRole(RoleName::Teller);

        $this->getJson('/api/v1/organization/structure')->assertForbidden();
    });

    it('describes the whole institution for an administrator', function (): void {
        headOfficeBranch();
        actingAsRole(RoleName::Admin);

        $data = $this->getJson('/api/v1/organization/structure')->assertOk()->json('data');

        expect($data['headOffice'])->not->toBeNull()
            ->and($data['zones'])->not->toBeEmpty()
            ->and($data['staffByTier'])->toHaveKey(OrganizationTier::HeadOffice->value)
            // Every role appears, including the ones nobody holds yet.
            ->and($data['staffByRole'])->toHaveCount(count(RoleName::cases()));
    });

    it('names branches no zone supervises rather than hiding them', function (): void {
        headOfficeBranch();

        $orphan = Branch::query()->where('is_head_office', false)->firstOrFail();
        $orphan->update(['zone_id' => null]);

        actingAsRole(RoleName::Admin);

        $names = collect($this->getJson('/api/v1/organization/structure')->assertOk()->json('data.unzonedBranches'))
            ->pluck('name')->all();

        expect($names)->toContain($orphan->name);
    });
});

describe('every user can ask where they sit', function (): void {
    it('tells a branch officer their branch, their scope and who supervises them', function (): void {
        headOfficeBranch();

        $branch = Branch::query()->where('is_head_office', false)->whereNotNull('zone_id')->firstOrFail();
        officerAt($branch->name, RoleName::LoanOfficer);

        $data = $this->getJson('/api/v1/organization/me')->assertOk()->json('data');

        expect($data['tier'])->toBe(OrganizationTier::Branch->value)
            ->and($data['branch']['name'])->toBe($branch->name)
            ->and($data['visibleBranchCount'])->toBeGreaterThan(0)
            ->and(collect($data['reportsTo'])->pluck('tier')->all())
            ->toContain(OrganizationTier::Zone->value);
    });

    it('needs no permission beyond being signed in', function (): void {
        // A teller holds neither loans.view nor the org grant.
        actingAsRole(RoleName::Teller);

        $this->getJson('/api/v1/organization/me')->assertOk();
    });
});

describe('the System account — a permanent platform rule', function (): void {
    it('cannot log in', function (): void {
        $system = User::query()->where('phone', SystemUserSeeder::PHONE)->sole();

        expect($system->status)->toBe(UserStatus::System)
            ->and($system->canAuthenticate())->toBeFalse();

        /*
         * And not merely by policy: the endpoint refuses it, and issues nothing.
         *
         * The status is 422 rather than 401 because "SYSTEM" is not a phone
         * number and never gets as far as the credential check — an accidental
         * fourth barrier on top of the status, the unknown password and the
         * empty permission set. What is asserted is the outcome that matters:
         * no token comes back.
         */
        $response = $this->postJson('/api/v1/auth/login', [
            'phone' => SystemUserSeeder::PHONE,
            'password' => 'whatever-somebody-guesses',
            'deviceName' => 'test',
        ]);

        expect($response->status())->toBeGreaterThanOrEqual(400)
            ->and($response->json('data.token'))->toBeNull();
    });

    it('holds no permissions at all', function (): void {
        $system = User::query()->where('phone', SystemUserSeeder::PHONE)->sole();

        expect($system->role->permissions)->toHaveCount(0)
            ->and($system->hasPermission(PermissionName::LoansView))->toBeFalse()
            ->and($system->hasPermission(PermissionName::AdminOrgSettings))->toBeFalse();
    });

    it('is what SystemActor resolves — never a super admin', function (): void {
        $this->seed(Database\Seeders\UserSeeder::class);

        $actor = app(SystemActor::class)->resolve();

        expect($actor->phone)->toBe(SystemUserSeeder::PHONE)
            ->and($actor->roleName())->toBe(RoleName::System)
            ->and($actor->roleName())->not->toBe(RoleName::SuperAdmin);
    });

    it('refuses to attribute a posting when no System account exists', function (): void {
        /*
         * A missing System account is a deployment error. Falling back to a real
         * person would produce months of entries attributed to somebody who did
         * not make them — which is exactly what the client ruled out.
         */
        User::query()->where('phone', SystemUserSeeder::PHONE)->forceDelete();

        expect(fn () => app(SystemActor::class)->resolve())
            ->toThrow(ConfigurationException::class, 'System account has not been initialized. Run database seeders.');
    });

    it('is refused a second copy by the database itself', function (): void {
        /*
         * Application guards can be bypassed by a hand-run insert or a botched
         * data migration. Two system accounts would split automated postings
         * across two identities and destroy the one property the rule exists
         * for, so the constraint lives in the schema.
         */
        expect(fn () => User::query()->create([
            'name' => 'Impostor',
            'phone' => 'SYSTEM-2',
            'password' => 'irrelevant',
            'role_id' => Role::query()->where('name', RoleName::System->value)->value('id'),
            'status' => UserStatus::System,
        ]))->toThrow(QueryException::class);

        expect(User::query()->where('status', UserStatus::System->value)->count())->toBe(1);
    });

    it('is hidden from user administration', function (): void {
        actingAsRole(RoleName::Admin);

        $listed = collect($this->getJson('/api/v1/users')->assertOk()->json('data'))->pluck('phone');

        expect($listed)->not->toContain(SystemUserSeeder::PHONE);
    });

    it('cannot be read, edited, suspended or deleted through the API', function (): void {
        $system = User::query()->where('phone', SystemUserSeeder::PHONE)->sole();

        actingAsRole(RoleName::Admin);

        /*
         * 404 rather than 403 throughout: the account is hidden from the list,
         * and refusing it by name would confirm its existence and its id to
         * anybody probing. From user administration's point of view it is not
         * there.
         */
        $this->getJson("/api/v1/users/{$system->id}")->assertNotFound();

        // A complete, otherwise-valid payload, so the refusal is the guard's
        // and not validation's.
        $this->putJson("/api/v1/users/{$system->id}", [
            'name' => 'Renamed',
            'phone' => '0755000111',
            'role' => RoleName::Admin->value,
        ])->assertNotFound();

        $this->patchJson("/api/v1/users/{$system->id}/status", ['status' => 'active'])->assertNotFound();
        $this->deleteJson("/api/v1/users/{$system->id}")->assertNotFound();

        expect($system->fresh()->status)->toBe(UserStatus::System);
    });

    it('cannot have its status given to a person', function (): void {
        /*
         * The unique index stops a SECOND system account. On a database that
         * has not been seeded there is no first one, so promoting a human would
         * succeed — which is why `system` is not an assignable status at all.
         */
        $victim = User::factory()->role(RoleName::Teller)->create();

        actingAsRole(RoleName::Admin);

        $this->patchJson("/api/v1/users/{$victim->id}/status", ['status' => UserStatus::System->value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        expect($victim->fresh()->status)->toBe(UserStatus::Active);
    });

    it('cannot have its role given to a person', function (): void {
        actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/users', [
            'name' => 'Someone',
            'phone' => '0755999888',
            'password' => 'Password1!',
            'passwordConfirmation' => 'Password1!',
            'role' => RoleName::System->value,
        ])->assertUnprocessable()->assertJsonValidationErrors(['role']);
    });

    it('cannot be sent a password reset', function (): void {
        $system = User::query()->where('phone', SystemUserSeeder::PHONE)->sole();

        /*
         * Two independent barriers. It has no email, so the broker cannot find
         * it; and `canAuthenticate()` is false, so the reset action returns
         * before issuing a link even if one were found.
         */
        expect($system->email)->toBeNull()
            ->and($system->canAuthenticate())->toBeFalse();
    });

    it('reports the platform as not ready when it is missing', function (): void {
        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('data.systemAccount', 'initialized');

        User::query()->where('phone', SystemUserSeeder::PHONE)->forceDelete();

        /*
         * An installation missing the account answers ordinary requests fine
         * and fails every automated posting. A health check that said "ok" up
         * to that point would be telling a load balancer to send traffic to an
         * instance that cannot do half its job.
         */
        $this->getJson('/api/v1/health')
            ->assertStatus(503)
            ->assertJsonPath('data.systemAccount', 'missing');
    });

    it('is posted to no office, so nothing narrows what a nightly job sees', function (): void {
        $system = User::query()->where('phone', SystemUserSeeder::PHONE)->sole();

        expect($system->branch_id)->toBeNull()
            ->and($system->zone_id)->toBeNull()
            ->and($system->region_id)->toBeNull();
    });
});

describe('the roles the structure needs — client decisions 2 and 3', function (): void {
    it('gives Head Office Credit no role of its own, only permissions', function (): void {
        /*
         * Client Decision 2: "Do NOT create another role. Head Office Credit is
         * simply a Head Office employee with loans.credit_review and
         * loans.review_cross_branch."
         */
        $names = array_map(static fn (RoleName $r): string => $r->value, RoleName::cases());

        expect($names)->not->toContain('credit_manager')
            ->and($names)->not->toContain('head_office_credit');

        $ho = headOfficeBranch();
        $credit = User::factory()->role(RoleName::CreditOfficer)->create(['branch_id' => $ho->getKey()]);
        $credit->givePermissionTo(PermissionName::LoansReviewCrossBranch->value);

        expect(hierarchy()->tierFor($credit))->toBe(OrganizationTier::HeadOffice)
            ->and($credit->hasPermission(PermissionName::LoansCreditReview))->toBeTrue()
            ->and($credit->hasPermission(PermissionName::LoansReviewCrossBranch))->toBeTrue();
    });

    it('does not give Admin the Hold grant', function (): void {
        // Client Decision 3: "Leave Hold separate from Approve. Admin does NOT
        // automatically receive Hold."
        $admin = userWithRole(RoleName::Admin);

        expect($admin->hasPermission(PermissionName::LoansApprove))->toBeTrue()
            ->and($admin->hasPermission(PermissionName::LoansHold))->toBeFalse();
    });

    it('keeps tellering away from the zone tier', function (): void {
        // "Zone Managers... No teller functions."
        $zone = userWithRole(RoleName::ZoneManager);

        expect($zone->hasPermission(PermissionName::LoansZoneApprove))->toBeTrue()
            ->and($zone->hasPermission(PermissionName::ReportsView))->toBeTrue()
            ->and($zone->hasPermission(PermissionName::RepaymentsCashEntry))->toBeFalse();
    });

    it('keeps the counter and the reconciliation in different hands', function (): void {
        $cashier = userWithRole(RoleName::Cashier);
        $accountant = userWithRole(RoleName::Accountant);

        // The person holding the cash is never the person who agrees it
        // reached the bank.
        expect($cashier->hasPermission(PermissionName::RepaymentsCashEntry))->toBeTrue()
            ->and($cashier->hasPermission(PermissionName::RepaymentsReconcile))->toBeFalse()
            ->and($accountant->hasPermission(PermissionName::RepaymentsReconcile))->toBeTrue()
            ->and($accountant->hasPermission(PermissionName::RepaymentsCashEntry))->toBeFalse();
    });

    it('lets a recovery officer record money back but not forgive a debt', function (): void {
        $recovery = userWithRole(RoleName::RecoveryOfficer);

        expect($recovery->hasPermission(PermissionName::LoansRecover))->toBeTrue()
            ->and($recovery->hasPermission(PermissionName::LoansWriteOff))->toBeFalse();
    });

    it('lets customer care see the book and decide nothing on it', function (): void {
        $care = userWithRole(RoleName::CustomerCare);

        expect($care->hasPermission(PermissionName::CustomersView))->toBeTrue()
            ->and($care->hasPermission(PermissionName::LoansView))->toBeTrue()
            ->and($care->hasPermission(PermissionName::LoansApprove))->toBeFalse()
            ->and($care->hasPermission(PermissionName::RepaymentsCashEntry))->toBeFalse()
            ->and($care->hasPermission(PermissionName::LedgerView))->toBeFalse();
    });

    it('does not let the head office manager both approve and disburse', function (): void {
        $manager = userWithRole(RoleName::HeadOfficeManager);

        expect($manager->hasPermission(PermissionName::LoansApprove))->toBeTrue()
            // Seniority is not a reason to collapse separation of duties.
            ->and($manager->hasPermission(PermissionName::LoansDisburse))->toBeFalse()
            ->and($manager->hasPermission(PermissionName::AccountingPeriodClose))->toBeFalse();
    });
});

<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Organization\Services\BranchScope;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Region;
use App\Models\User;
use App\Models\Zone;
use Laravel\Sanctum\Sanctum;

/**
 * Places a user at a named branch, optionally with a zone or region scope,
 * and authenticates them.
 */
function actorAt(RoleName $role, string $branchName, ?string $zoneName = null, ?string $regionName = null): User
{
    $user = User::factory()->role($role)->create([
        'branch_id' => Branch::query()->where('name', $branchName)->value('id'),
        'zone_id' => $zoneName === null ? null : Zone::query()->where('name', $zoneName)->value('id'),
        'region_id' => $regionName === null ? null : Region::query()->where('name', $regionName)->value('id'),
    ]);

    Sanctum::actingAs($user, ['*']);

    return $user;
}

describe('scope resolution', function (): void {
    beforeEach(function (): void {
        seedOrganization();
    });

    it('limits a branch-scoped role to its own branch', function (): void {
        // §13: "every role is branch-scoped unless explicitly granted
        // otherwise". A Loan Officer holds no branches.view_all.
        $officer = actorAt(RoleName::LoanOfficer, 'Kakonko');

        $names = collect($this->getJson('/api/v1/branches')->json('data'))->pluck('name');

        expect($names->all())->toBe(['Kakonko']);
    });

    it('includes sub-branches that roll up into the home branch', function (): void {
        // NEW KALENGE rolls up into Lindi (§12), so a Lindi user reading a
        // branch total that excluded it would read an incomplete figure.
        actorAt(RoleName::Teller, 'Lindi');

        $names = collect($this->getJson('/api/v1/branches')->json('data'))->pluck('name');

        expect($names->all())->toEqualCanonicalizing(['Lindi', 'NEW KALENGE']);
    });

    it('gives a zone manager their whole zone and nothing beyond it', function (): void {
        actorAt(RoleName::ZoneManager, 'Kakonko', zoneName: 'West Zone');

        $names = collect($this->getJson('/api/v1/branches')->json('data'))->pluck('name');

        // West Zone is Kakonko + Missenyi; Lindi and NEW KALENGE are East.
        expect($names->all())->toEqualCanonicalizing(['Kakonko', 'Missenyi'])
            ->and($names)->not->toContain('Head Office');
    });

    it('gives a regional manager their whole region and nothing beyond it', function (): void {
        actorAt(RoleName::RegionalManager, 'Missenyi', regionName: 'Kagera');

        $names = collect($this->getJson('/api/v1/branches')->json('data'))->pluck('name');

        expect($names->all())->toBe(['Missenyi']);
    });

    it('gives HQ roles every branch', function (): void {
        foreach ([RoleName::SuperAdmin, RoleName::Admin, RoleName::Finance, RoleName::Auditor] as $role) {
            actorAt($role, 'Head Office');

            expect($this->getJson('/api/v1/branches')->json('data'))
                ->toHaveCount(5, "Role [{$role->value}] should see every branch");
        }
    });

    it('keeps the credit officer strictly branch-scoped', function (): void {
        // §13 is emphatic: "Credit Officer is strictly branch-scoped, no
        // exceptions." There is no permission that widens it.
        actorAt(RoleName::CreditOfficer, 'Missenyi');

        expect(collect($this->getJson('/api/v1/branches')->json('data'))->pluck('name')->all())
            ->toBe(['Missenyi']);
    });
});

describe('cross-branch access', function (): void {
    beforeEach(function (): void {
        seedOrganization();
    });

    it('refuses to show a branch outside the user scope and audits the attempt', function (): void {
        $officer = actorAt(RoleName::LoanOfficer, 'Kakonko');
        $lindi = Branch::query()->where('name', 'Lindi')->sole();

        $this->getJson("/api/v1/branches/{$lindi->id}")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'BRANCH_SCOPE_VIOLATION');

        // §13: "cross-branch snooping attempts are themselves an auditable
        // event".
        $log = AuditLog::query()->where('action', AuditAction::BranchScopeViolation->value)->sole();

        expect($log->after_json['attempted_branch_id'])->toBe($lindi->id)
            ->and($log->after_json['identifier'])->toBe((string) $officer->id);
    });

    it('allows a user to read their own branch', function (): void {
        actorAt(RoleName::LoanOfficer, 'Kakonko');
        $kakonko = Branch::query()->where('name', 'Kakonko')->sole();

        $this->getJson("/api/v1/branches/{$kakonko->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Kakonko');

        expect(AuditLog::query()->where('action', AuditAction::BranchScopeViolation->value)->exists())->toBeFalse();
    });

    it('lets a zone manager read another branch inside their zone', function (): void {
        actorAt(RoleName::ZoneManager, 'Kakonko', zoneName: 'West Zone');
        $missenyi = Branch::query()->where('name', 'Missenyi')->sole();

        $this->getJson("/api/v1/branches/{$missenyi->id}")->assertOk();
    });

    it('stops a zone manager reading a branch in another zone', function (): void {
        actorAt(RoleName::ZoneManager, 'Kakonko', zoneName: 'West Zone');
        $lindi = Branch::query()->where('name', 'Lindi')->sole();

        $this->getJson("/api/v1/branches/{$lindi->id}")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'BRANCH_SCOPE_VIOLATION');
    });
});

describe('applyToColumn', function (): void {
    it('scopes an arbitrary branch-keyed query for later modules to reuse', function (): void {
        seedOrganization();

        $teller = User::factory()->role(RoleName::Teller)->create([
            'branch_id' => Branch::query()->where('name', 'Lindi')->value('id'),
        ]);

        // Stand-in for a customers/loans/payments query in a later phase.
        $visible = app(BranchScope::class)
            ->applyToColumn(Branch::query(), $teller, 'id')
            ->pluck('name')
            ->all();

        expect($visible)->toEqualCanonicalizing(['Lindi', 'NEW KALENGE']);
    });
});

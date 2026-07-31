<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CompanyProfile;
use App\Models\District;
use App\Models\Region;
use App\Models\User;
use App\Models\Zone;

function branchPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Mbeya City',
        'regionId' => Region::query()->where('name', 'Mbeya')->value('id'),
        'zoneId' => Zone::query()->where('name', 'East Zone')->value('id'),
        'phone' => '0700000123',
        'type' => 'main',
        'parentBranchId' => null,
        'status' => 'active',
    ], $overrides);
}

describe('branches', function (): void {
    beforeEach(function (): void {
        seedOrganization();
    });

    it('creates a branch in the frontend schema shape', function (): void {
        $admin = actingAsRole(RoleName::Admin);

        $response = $this->postJson('/api/v1/branches', branchPayload());

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Mbeya City')
            ->assertJsonPath('data.type', 'main')
            ->assertJsonPath('data.isHeadOffice', false)
            ->assertJsonPath('data.createdBy', (string) $admin->id)
            ->assertJsonStructure(['data' => [
                'id', 'name', 'regionId', 'zoneId', 'phone', 'type',
                'parentBranchId', 'isHeadOffice', 'status', 'createdBy', 'deletedAt',
            ]]);

        // types/branch.ts declares every id as z.string().
        expect($response->json('data.id'))->toBeString()
            ->and($response->json('data.regionId'))->toBeString();
    });

    it('never lets create set head office', function (): void {
        actingAsRole(RoleName::Admin);

        // The frontend hardcodes isHeadOffice:false on create; even if a
        // client sent it, two head offices must not become expressible.
        $this->postJson('/api/v1/branches', branchPayload(['isHeadOffice' => true]))
            ->assertCreated()
            ->assertJsonPath('data.isHeadOffice', false);

        expect(Branch::query()->where('is_head_office', true)->count())->toBe(1);
    });

    it('rejects a duplicate branch name and an unknown region', function (): void {
        actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/branches', branchPayload(['name' => 'Lindi']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/v1/branches', branchPayload(['regionId' => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['regionId']);
    });

    it('moves head office atomically and keeps the company profile in step', function (): void {
        actingAsRole(RoleName::Admin);

        $kakonko = Branch::query()->where('name', 'Kakonko')->sole();

        $this->postJson("/api/v1/branches/{$kakonko->id}/head-office")
            ->assertOk()
            ->assertJsonPath('data.isHeadOffice', true);

        // Exactly one head office, always (spec §2.2 / §12).
        expect(Branch::query()->where('is_head_office', true)->count())->toBe(1)
            ->and(Branch::query()->where('name', 'Head Office')->sole()->is_head_office)->toBeFalse()
            ->and(CompanyProfile::current()->headquarters_branch_id)->toBe($kakonko->id);

        expect(AuditLog::query()->where('action', AuditAction::HeadOfficeChanged->value)->exists())->toBeTrue();
    });

    it('refuses to delete the head office', function (): void {
        actingAsRole(RoleName::Admin);
        $hq = Branch::query()->where('is_head_office', true)->sole();

        $this->deleteJson("/api/v1/branches/{$hq->id}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'HEAD_OFFICE_PROTECTED');
    });

    it('refuses to delete a branch that still has sub-branches or staff', function (): void {
        actingAsRole(RoleName::Admin);

        $lindi = Branch::query()->where('name', 'Lindi')->sole();

        // NEW KALENGE rolls up into Lindi. The FK is RESTRICT, so without this
        // guard the request would surface as a 500.
        $this->deleteJson("/api/v1/branches/{$lindi->id}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'RESOURCE_IN_USE');

        $missenyi = Branch::query()->where('name', 'Missenyi')->sole();
        User::factory()->role(RoleName::Teller)->create(['branch_id' => $missenyi->getKey()]);

        $this->deleteJson("/api/v1/branches/{$missenyi->id}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'RESOURCE_IN_USE');
    });

    it('soft-deletes an empty branch', function (): void {
        actingAsRole(RoleName::Admin);
        $kalenge = Branch::query()->where('name', 'NEW KALENGE')->sole();

        $this->deleteJson("/api/v1/branches/{$kalenge->id}")->assertOk();

        $this->assertSoftDeleted('branches', ['id' => $kalenge->id]);
        expect(Branch::withTrashed()->whereKey($kalenge->id)->exists())->toBeTrue();
    });
});

describe('zones', function (): void {
    beforeEach(function (): void {
        seedOrganization();
    });

    it('lists zones with manager and branch count', function (): void {
        actingAsRole(RoleName::Admin);

        $response = $this->getJson('/api/v1/zones');

        $response->assertOk()->assertJsonCount(2, 'data');

        $west = collect($response->json('data'))->firstWhere('name', 'West Zone');
        expect($west['branchCount'])->toBe(2)
            ->and($west['id'])->toBeString();
    });

    it('creates and updates a zone', function (): void {
        actingAsRole(RoleName::Admin);
        $manager = User::factory()->role(RoleName::ZoneManager)->create();

        $created = $this->postJson('/api/v1/zones', [
            'name' => 'South Zone',
            'zoneManagerId' => $manager->id,
        ])->assertCreated();

        $zoneId = $created->json('data.id');

        $this->putJson("/api/v1/zones/{$zoneId}", [
            'name' => 'Southern Zone',
            'zoneManagerId' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Southern Zone')
            ->assertJsonPath('data.zoneManagerId', null);
    });

    it('refuses to delete a zone with branches or scoped users', function (): void {
        actingAsRole(RoleName::Admin);
        $west = Zone::query()->where('name', 'West Zone')->sole();

        $this->deleteJson("/api/v1/zones/{$west->id}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'RESOURCE_IN_USE');

        // Empty zone, but a Zone Manager is still scoped to it.
        $empty = Zone::query()->create(['name' => 'Empty Zone']);
        User::factory()->role(RoleName::ZoneManager)->create(['zone_id' => $empty->getKey()]);

        $this->deleteJson("/api/v1/zones/{$empty->id}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'RESOURCE_IN_USE');
    });

    it('soft-deletes an unreferenced zone', function (): void {
        actingAsRole(RoleName::Admin);
        $empty = Zone::query()->create(['name' => 'Empty Zone']);

        $this->deleteJson("/api/v1/zones/{$empty->id}")->assertOk();

        $this->assertSoftDeleted('zones', ['id' => $empty->id]);
    });
});

describe('regions', function (): void {
    beforeEach(function (): void {
        seedOrganization();
    });

    it('lists regions', function (): void {
        actingAsRole(RoleName::Admin);

        $this->getJson('/api/v1/regions')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure(['data' => [['id', 'name']]]);
    });

    it('refuses to delete a region that still has districts, branches or users', function (): void {
        actingAsRole(RoleName::Admin);
        $mwanza = Region::query()->where('name', 'Mwanza')->sole();

        $this->deleteJson("/api/v1/regions/{$mwanza->id}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'RESOURCE_IN_USE');

        expect(Region::query()->whereKey($mwanza->id)->exists())->toBeTrue();
    });

    it('hard-deletes an unreferenced region', function (): void {
        actingAsRole(RoleName::Admin);
        $created = $this->postJson('/api/v1/regions', ['name' => 'Dodoma'])->assertCreated();

        $this->deleteJson("/api/v1/regions/{$created->json('data.id')}")->assertOk();

        // Regions carry no deleted_at in spec §2.2 — this really removes it.
        expect(Region::query()->where('name', 'Dodoma')->exists())->toBeFalse();
    });
});

describe('company profile', function (): void {
    beforeEach(function (): void {
        seedOrganization();
    });

    it('returns the singleton with the literal id the frontend expects', function (): void {
        actingAsRole(RoleName::Admin);

        $this->getJson('/api/v1/company-profile')
            ->assertOk()
            // types/organization.ts declares id as z.literal("company-profile").
            ->assertJsonPath('data.id', 'company-profile')
            // The trading name is the only company detail any legacy screen
            // evidences — it is the wordmark on every page. The registered
            // name, TIN and address are seeded empty rather than invented.
            ->assertJsonPath('data.tradingName', 'MikopoFasta')
            ->assertJsonStructure(['data' => [
                'id', 'legalName', 'tradingName', 'registrationNumber', 'tinNumber',
                'phone', 'email', 'address', 'headquartersBranchId', 'updatedBy', 'updatedAt',
            ]]);
    });

    it('updates the profile and stamps the editor', function (): void {
        $admin = actingAsRole(RoleName::Admin);

        $this->putJson('/api/v1/company-profile', [
            'legalName' => 'Mikopofasta Microfinance PLC',
            'tradingName' => 'Mikopofasta',
            'registrationNumber' => 'REG-2019-004821',
            'tinNumber' => '109-874-321',
            'phone' => '0700000001',
            'email' => 'hello@mikopofasta.co.tz',
            'address' => 'P.O. Box 1234, Mwanza, Tanzania',
            'headquartersBranchId' => Branch::query()->where('is_head_office', true)->value('id'),
        ])
            ->assertOk()
            ->assertJsonPath('data.legalName', 'Mikopofasta Microfinance PLC')
            ->assertJsonPath('data.updatedBy', (string) $admin->id);

        expect(AuditLog::query()->where('action', AuditAction::CompanyProfileUpdated->value)->exists())->toBeTrue();
    });
});

describe('address lookups', function (): void {
    beforeEach(function (): void {
        seedOrganization();
    });

    it('serves the district → ward → street chain filtered by parent', function (): void {
        // A Loan Officer holds no admin permission but must be able to build a
        // customer address in the registration wizard.
        actingAsRole(RoleName::LoanOfficer);

        $mwanza = Region::query()->where('name', 'Mwanza')->sole();

        $districts = $this->getJson("/api/v1/districts?region_id={$mwanza->id}")->assertOk();
        expect(collect($districts->json('data'))->pluck('name')->all())
            ->toEqualCanonicalizing(['Nyamagana', 'Ilemela']);

        $nyamagana = District::query()->where('name', 'Nyamagana')->sole();

        $wards = $this->getJson("/api/v1/wards?district_id={$nyamagana->id}")->assertOk();
        expect(collect($wards->json('data'))->pluck('name')->all())
            ->toEqualCanonicalizing(['Mirongo', 'Isamilo']);

        $mirongoId = collect($wards->json('data'))->firstWhere('name', 'Mirongo')['id'];

        $streets = $this->getJson("/api/v1/streets?ward_id={$mirongoId}")->assertOk();
        expect(collect($streets->json('data'))->pluck('name')->all())
            ->toEqualCanonicalizing(['Mirongo Street', 'Kenyatta Road']);
    });

    it('returns everything when no parent filter is given', function (): void {
        actingAsRole(RoleName::LoanOfficer);

        expect($this->getJson('/api/v1/districts')->json('data'))->toHaveCount(12);
    });
});

describe('rbac', function (): void {
    beforeEach(function (): void {
        seedOrganization();
    });

    it('denies every organization write to a role without admin.org_settings', function (): void {
        actingAsRole(RoleName::LoanOfficer);

        $branch = Branch::query()->where('name', 'Kakonko')->sole();
        $zone = Zone::query()->where('name', 'West Zone')->sole();
        $region = Region::query()->where('name', 'Mwanza')->sole();

        /*
         * Every payload here is deliberately VALID. Laravel resolves a
         * FormRequest before the controller runs, so an invalid body would
         * return 422 and the assertion would never reach the authorization
         * check it exists to prove.
         */
        $this->postJson('/api/v1/branches', branchPayload())->assertForbidden();
        $this->putJson("/api/v1/branches/{$branch->id}", branchPayload(['name' => 'Kakonko']))->assertForbidden();
        $this->postJson("/api/v1/branches/{$branch->id}/head-office")->assertForbidden();
        $this->deleteJson("/api/v1/branches/{$branch->id}")->assertForbidden();

        $this->postJson('/api/v1/zones', ['name' => 'South Zone', 'zoneManagerId' => null])->assertForbidden();
        $this->putJson("/api/v1/zones/{$zone->id}", ['name' => 'West Zone', 'zoneManagerId' => null])->assertForbidden();
        $this->deleteJson("/api/v1/zones/{$zone->id}")->assertForbidden();

        $this->postJson('/api/v1/regions', ['name' => 'Dodoma'])->assertForbidden();
        $this->putJson("/api/v1/regions/{$region->id}", ['name' => 'Mwanza'])->assertForbidden();
        $this->deleteJson("/api/v1/regions/{$region->id}")->assertForbidden();

        $this->putJson('/api/v1/company-profile', [
            'legalName' => 'Someone Else Ltd',
            'tradingName' => 'Someone Else',
            'registrationNumber' => 'REG-1',
            'tinNumber' => '000-000-000',
            'phone' => '0700000000',
            'email' => 'a@b.co.tz',
            'address' => 'Nowhere',
            'headquartersBranchId' => null,
        ])->assertForbidden();

        // Nothing actually changed.
        expect(Region::query()->where('name', 'Dodoma')->exists())->toBeFalse()
            ->and(CompanyProfile::current()->trading_name)->toBe('MikopoFasta');
    });

    it('allows reads for any authenticated user', function (): void {
        actingAsRole(RoleName::Teller);

        $this->getJson('/api/v1/branches')->assertOk();
        $this->getJson('/api/v1/zones')->assertOk();
        $this->getJson('/api/v1/regions')->assertOk();
        $this->getJson('/api/v1/company-profile')->assertOk();
        $this->getJson('/api/v1/districts')->assertOk();
    });

    it('requires authentication throughout', function (): void {
        $this->getJson('/api/v1/branches')->assertUnauthorized();
        $this->getJson('/api/v1/zones')->assertUnauthorized();
        $this->getJson('/api/v1/regions')->assertUnauthorized();
        $this->getJson('/api/v1/company-profile')->assertUnauthorized();
        $this->getJson('/api/v1/districts')->assertUnauthorized();
    });

    it('lets an auditor read but never write', function (): void {
        actingAsRole(RoleName::Auditor);

        $this->getJson('/api/v1/branches')->assertOk();
        $this->postJson('/api/v1/branches', branchPayload())->assertForbidden();
    });
});

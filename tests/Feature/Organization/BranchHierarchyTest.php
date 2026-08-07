<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Organization\Enums\BranchType;
use App\Domain\Organization\Services\BranchHierarchy;
use App\Models\Branch;

describe('traversal', function (): void {
    it('walks descendants down an arbitrarily deep tree', function (): void {
        seedOrganization();

        $lindi = Branch::query()->where('name', 'Lindi')->sole();
        $kalenge = Branch::query()->where('name', 'NEW KALENGE')->sole();

        // A third level, to prove the walk is not just one hop.
        $depot = Branch::query()->create([
            'name' => 'Kalenge Depot',
            'code' => 'KLD',
            'phone' => '0700000099',
            'type' => BranchType::Sub,
            'parent_branch_id' => $kalenge->getKey(),
        ]);

        expect($lindi->descendants()->pluck('name')->all())
            ->toEqualCanonicalizing(['NEW KALENGE', 'Kalenge Depot'])
            ->and($lindi->selfAndDescendantIds())
            ->toEqualCanonicalizing([$lindi->id, $kalenge->id, $depot->id]);
    });

    it('walks ancestors up to the root, nearest first', function (): void {
        seedOrganization();

        $kalenge = Branch::query()->where('name', 'NEW KALENGE')->sole();
        $depot = Branch::query()->create([
            'name' => 'Kalenge Depot',
            'code' => 'KLD',
            'phone' => '0700000099',
            'type' => BranchType::Sub,
            'parent_branch_id' => $kalenge->getKey(),
        ]);

        expect($depot->ancestors()->pluck('name')->all())->toBe(['NEW KALENGE', 'Lindi']);
    });

    it('returns no ancestors for a root branch', function (): void {
        seedOrganization();

        expect(Branch::query()->where('name', 'Head Office')->sole()->ancestors())->toBeEmpty();
    });

    it('terminates on a cycle instead of hanging', function (): void {
        seedOrganization();

        $lindi = Branch::query()->where('name', 'Lindi')->sole();
        $kalenge = Branch::query()->where('name', 'NEW KALENGE')->sole();

        // Force a cycle past the action-layer guard, the way a bad manual
        // UPDATE would. The walk must still return.
        Branch::query()->whereKey($lindi->getKey())->update(['parent_branch_id' => $kalenge->getKey()]);

        $ancestors = $lindi->fresh()->ancestors();

        expect($ancestors->pluck('name')->all())->toBe(['NEW KALENGE']);
    });

    it('builds a nested tree with depths', function (): void {
        seedOrganization();

        $tree = app(BranchHierarchy::class)->tree(Branch::query()->get());

        // Four roots — Head Office, Kakonko, Lindi, Missenyi — with New
        // Kalenge nested under Lindi rather than appearing at the top level.
        expect($tree)->toHaveCount(4);

        $lindiNode = collect($tree)->firstWhere(fn (array $n): bool => $n['branch']->name === 'Lindi');

        expect($lindiNode['depth'])->toBe(0)
            ->and($lindiNode['children'])->toHaveCount(1)
            ->and($lindiNode['children'][0]['branch']->name)->toBe('NEW KALENGE')
            ->and($lindiNode['children'][0]['depth'])->toBe(1);
    });

    it('flattens parents before children', function (): void {
        seedOrganization();

        $flat = app(BranchHierarchy::class)->flatten(Branch::query()->get());
        $names = array_map(static fn (array $row): string => $row['branch']->name, $flat);

        expect($names)->toHaveCount(5)
            ->and(array_search('Lindi', $names, true))
            ->toBeLessThan(array_search('NEW KALENGE', $names, true));
    });
});

describe('cycle prevention', function (): void {
    it('refuses to make a branch its own parent', function (): void {
        actingAsRole(RoleName::Admin);
        seedOrganization();

        $lindi = Branch::query()->where('name', 'Lindi')->sole();

        $this->putJson("/api/v1/branches/{$lindi->id}", [
            'name' => 'Lindi',
            'regionId' => $lindi->region_id,
            'zoneId' => $lindi->zone_id,
            'phone' => $lindi->phone,
            'type' => 'main',
            'parentBranchId' => $lindi->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parentBranchId']);
    });

    it('refuses to reparent a branch under its own descendant', function (): void {
        actingAsRole(RoleName::Admin);
        seedOrganization();

        $lindi = Branch::query()->where('name', 'Lindi')->sole();
        $kalenge = Branch::query()->where('name', 'NEW KALENGE')->sole();

        // Lindi → NEW KALENGE → Lindi would close a loop and make every later
        // traversal non-terminating.
        $this->putJson("/api/v1/branches/{$lindi->id}", [
            'name' => 'Lindi',
            'regionId' => $lindi->region_id,
            'zoneId' => $lindi->zone_id,
            'phone' => $lindi->phone,
            'type' => 'main',
            'parentBranchId' => $kalenge->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'BRANCH_HIERARCHY_CYCLE');

        expect($lindi->refresh()->parent_branch_id)->toBeNull();
    });

    it('allows a legitimate reparent', function (): void {
        actingAsRole(RoleName::Admin);
        seedOrganization();

        $kalenge = Branch::query()->where('name', 'NEW KALENGE')->sole();
        $kakonko = Branch::query()->where('name', 'Kakonko')->sole();

        $this->putJson("/api/v1/branches/{$kalenge->id}", [
            'name' => 'NEW KALENGE',
            'regionId' => $kalenge->region_id,
            'zoneId' => $kalenge->zone_id,
            'phone' => $kalenge->phone,
            'type' => 'sub',
            'parentBranchId' => $kakonko->id,
        ])->assertOk();

        expect($kalenge->refresh()->parent_branch_id)->toBe($kakonko->id);
    });
});

describe('hierarchy endpoint', function (): void {
    it('returns the nested tree', function (): void {
        actingAsRole(RoleName::Admin);
        seedOrganization();

        $response = $this->getJson('/api/v1/branches/hierarchy');

        $response->assertOk()
            ->assertJsonPath('meta.total', 5)
            ->assertJsonStructure(['data' => [['branch' => ['id', 'name'], 'depth', 'children']]]);

        $lindi = collect($response->json('data'))->firstWhere('branch.name', 'Lindi');

        expect($lindi['children'])->toHaveCount(1)
            ->and($lindi['children'][0]['branch']['name'])->toBe('NEW KALENGE');
    });

    it('is not mistaken for a branch id', function (): void {
        actingAsRole(RoleName::Admin);
        seedOrganization();

        // The literal route must win over the {branch} wildcard.
        $this->getJson('/api/v1/branches/hierarchy')
            ->assertOk()
            ->assertJsonMissingPath('data.id');
    });
});

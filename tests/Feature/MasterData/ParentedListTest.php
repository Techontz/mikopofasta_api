<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Models\MasterData\GovernmentBody;
use App\Models\MasterData\GovernmentCadre;
use App\Models\MasterData\GovernmentDepartment;
use App\Support\MasterDataRegistry;

/**
 * The lists that belong to a parent, served one parent at a time.
 *
 * WHAT THIS PREVENTS. The five customer types ask three levels deep — the
 * ministry, then its department, then the cadre inside that. `government_cadres`
 * holds every cadre of every ministry, so a "Cheo" dropdown that fetched the
 * table would offer a teacher's grades to somebody registering a nurse, and a
 * list that long is unusable even when it is correct.
 *
 * The narrowing is the contract, so it is what these assert: the right rows for
 * the parent, nothing at all without one, and a refusal for a slug that names
 * no list.
 */
beforeEach(function (): void {
    seedCustomerFoundation();

    $body = GovernmentBody::query()->create(['code' => 'TAMISEMI', 'name' => 'OFISI YA RAIS - TAMISEMI']);
    $other = GovernmentBody::query()->create(['code' => 'AFYA', 'name' => 'WIZARA YA AFYA']);

    $health = GovernmentDepartment::query()->create([
        'government_body_id' => $body->id, 'code' => 'AFYA', 'name' => 'Afya',
    ]);
    $schools = GovernmentDepartment::query()->create([
        'government_body_id' => $body->id, 'code' => 'ELIMU_MSINGI', 'name' => 'Elimu Msingi',
    ]);
    GovernmentDepartment::query()->create([
        'government_body_id' => $other->id, 'code' => 'TIBA', 'name' => 'Tiba',
    ]);

    GovernmentCadre::query()->create(['government_department_id' => $health->id, 'code' => 'DAKTARI', 'name' => 'Daktari']);
    GovernmentCadre::query()->create(['government_department_id' => $schools->id, 'code' => 'MWALIMU', 'name' => 'Mwalimu']);

    test()->body = $body;
    test()->health = $health;
});

it('returns only the children of the parent asked for', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    $names = collect(test()->getJson(
        '/api/v1/master-data/parented/government-departments?parent_id='.test()->body->id.'&active=1'
    )->assertOk()->json('data'))->pluck('name')->all();

    /* Both of TAMISEMI's, and not the other ministry's. */
    expect($names)->toEqualCanonicalizing(['Afya', 'Elimu Msingi']);
});

it('narrows the third level by its department, not by the ministry', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    $names = collect(test()->getJson(
        '/api/v1/master-data/parented/government-cadres?parent_id='.test()->health->id.'&active=1'
    )->assertOk()->json('data'))->pluck('name')->all();

    /* "Mwalimu" is under the same ministry but a different department. A
       cascade that only filtered by ministry would return it. */
    expect($names)->toBe(['Daktari']);
});

it('returns nothing at all when no parent is named', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    $data = test()->getJson('/api/v1/master-data/parented/government-cadres?active=1')->assertOk()->json('data');

    /* Not the whole table: that is the mistake this endpoint exists to avoid. */
    expect($data)->toBe([]);
});

it('refuses a slug that names no parented list', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    test()->getJson('/api/v1/master-data/parented/not-a-list')
        ->assertNotFound()
        ->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
});

it('refuses a flat list, which has no parent to narrow by', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    /* `banks` is a real list, but not a parented one — asking for it here is a
       mistake in the caller, not an empty answer. */
    test()->getJson('/api/v1/master-data/parented/banks?parent_id=1')->assertNotFound();
});

it('refuses an unauthenticated caller', function (): void {
    test()->getJson('/api/v1/master-data/parented/government-departments?parent_id=1')->assertUnauthorized();
});

it('knows every parented list it declares a parent column for', function (): void {
    /* The two maps are read by different callers — the controller resolves the
       model, the validator resolves the column — and a slug in one but not the
       other fails silently: the dropdown loads and the answer is accepted
       unchecked. */
    expect(array_keys(MasterDataRegistry::PARENTED))
        ->toEqualCanonicalizing(array_keys(MasterDataRegistry::PARENT_COLUMNS));
});

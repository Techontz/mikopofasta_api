<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Support\MasterDataRegistry;

/**
 * Every reference list in one request.
 *
 * WHAT THIS PREVENTS. The registration screen needs fourteen lists to render
 * one form and used to ask for them one at a time. Fourteen requests, fourteen
 * authentications and fourteen rate-limiter increments per visit exhausted the
 * authenticated allowance after a handful of page loads, and the screen then
 * refused to open with HTTP 429 — a form too expensive to display.
 *
 * The limit was never the defect. These assert the shape that removed the cost.
 */
beforeEach(function (): void {
    seedCustomerFoundation();
});

it('serves every flat list in a single response', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    $data = test()->getJson('/api/v1/master-data?active=1')->assertOk()->json('data');

    /* Every slug the client knows, present — a declared key that is never
       filled is an undefined waiting to reach a dropdown. */
    expect(array_keys($data))->toEqualCanonicalizing(array_keys(MasterDataRegistry::LISTS));
});

it('gives each list the same shape the single-list route gives it', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    $batch = test()->getJson('/api/v1/master-data?active=1')->assertOk()->json('data');
    $single = test()->getJson('/api/v1/master-data/id-types?active=1')->assertOk()->json('data');

    /* The point of the batch route is fewer requests, not a second contract. */
    expect($batch['id-types'])->toBe($single);
});

it('omits sector categories, which belong to a parent', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    $data = test()->getJson('/api/v1/master-data?active=1')->assertOk()->json('data');

    /* Returning every cadre of every employing body to a form that has not
       chosen one is the mistake the address lookups avoid. */
    expect($data)->not->toHaveKey('sector-categories');
});

it('is readable by any authenticated user, like the single-list route', function (): void {
    officerAt('Kakonko', RoleName::Teller);

    test()->getJson('/api/v1/master-data?active=1')->assertOk();
});

it('refuses an unauthenticated caller', function (): void {
    test()->getJson('/api/v1/master-data?active=1')->assertUnauthorized();
});

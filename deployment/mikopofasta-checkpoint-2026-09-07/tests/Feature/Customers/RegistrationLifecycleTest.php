<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Customers\Enums\CustomerApprovalStatus;
use App\Domain\Customers\Services\KycEvaluator;
use App\Models\Customer;

/**
 * SAVE IS NOT FINALISE.
 *
 * The registration wizard's third step writes a permanent customer and its
 * fourth verifies their face. These assert the boundary between the two, which
 * is the whole reason they are separate steps:
 *
 *     step 3  →  customer exists, awaiting face verification, NOT KYC complete
 *     step 4  →  a passing scan  →  KYC complete
 *     then    →  a manager approves  →  eligible to borrow
 *
 * Three distinct states that are easy to collapse into one. Registration
 * completeness, face verification and managerial approval answer different
 * questions and are asserted separately here.
 */
beforeEach(function (): void {
    seedCustomerFoundation();
});

/** What step three sends: everything, and no claim about a face. */
function stepThreePayload(array $overrides = []): array
{
    $payload = registrationPayload($overrides);
    unset($payload['faceVerifiedAt']);

    return $payload;
}

it('creates a permanent customer at step three and returns its id', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    $response = test()->postJson('/api/v1/customers', stepThreePayload())->assertCreated();

    $id = $response->json('data.id');

    expect($id)->not->toBeNull()
        ->and($response->json('data.customerNumber'))->not->toBeNull()
        /* Permanent: the record is on file the moment step three succeeds. */
        ->and(Customer::query()->whereKey($id)->exists())->toBeTrue();
});

it('leaves that customer awaiting face verification, not complete', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    test()->postJson('/api/v1/customers', stepThreePayload())->assertCreated();

    $customer = Customer::query()->latest('id')->firstOrFail();

    expect($customer->face_verified_at)->toBeNull();

    $kyc = app(KycEvaluator::class);
    $customer->load(['documents', 'idType', 'category', 'bankDetails']);

    /* The registration is saved and the KYC is not complete. Both at once is
       exactly the state step four exists to resolve. */
    expect($kyc->isComplete($customer))->toBeFalse();
});

it('refuses a registration that claims its own face verification', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    /*
     * THE BYPASS THIS CLOSES. Registration used to accept `faceVerifiedAt` and
     * write it straight to the column, so a client could create a customer who
     * was already verified with no scan on record — skipping step four and
     * satisfying KYC on an assertion nobody could check.
     */
    test()->postJson('/api/v1/customers', registrationPayload([
        'faceVerifiedAt' => now()->toIso8601String(),
    ]))->assertStatus(422)->assertJsonValidationErrors('faceVerifiedAt');

    expect(Customer::query()->count())->toBe(0);
});

it('verifies the SAME customer at step four, creating no second record', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    test()->postJson('/api/v1/customers', stepThreePayload())->assertCreated();

    $customer = Customer::query()->latest('id')->firstOrFail();
    $before = Customer::query()->count();

    test()->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())
        ->assertSuccessful();

    expect(Customer::query()->count())->toBe($before)
        ->and($customer->refresh()->face_verified_at)->not->toBeNull()
        /* The same row, not a new one. */
        ->and(Customer::query()->latest('id')->firstOrFail()->id)->toBe($customer->id);
});

it('records every face position the scanner reported', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    test()->postJson('/api/v1/customers', stepThreePayload())->assertCreated();
    $customer = Customer::query()->latest('id')->firstOrFail();

    test()->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())
        ->assertSuccessful();

    $scan = $customer->faceScans()->latest('id')->firstOrFail();

    /* The positions are the scanner's to define and are carried through
       verbatim — nothing here names them, and adding a sixth changes no code. */
    expect($scan->pose_sequence_completed)->toBeTrue()
        ->and($scan->checks)->not->toBeEmpty();
});

it('does not verify a customer whose scan failed', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    test()->postJson('/api/v1/customers', stepThreePayload())->assertCreated();
    $customer = Customer::query()->latest('id')->firstOrFail();

    test()->postJson(
        "/api/v1/customers/{$customer->id}/face-verify",
        faceScanPayload(['status' => 'failed', 'livenessPassed' => false]),
    )->assertSuccessful();

    /* The attempt is on file; the customer is not verified by it. */
    expect($customer->refresh()->face_verified_at)->toBeNull()
        ->and($customer->faceScans()->count())->toBe(1);
});

it('keeps the customer when the officer leaves after step three', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    test()->postJson('/api/v1/customers', stepThreePayload())->assertCreated();
    $customer = Customer::query()->latest('id')->firstOrFail();

    /* A new session entirely — the officer closed the tab and came back. */
    officerAt('Kakonko', RoleName::LoanOfficer);

    test()->getJson("/api/v1/customers/{$customer->id}")
        ->assertOk()
        ->assertJsonPath('data.id', (string) $customer->id)
        ->assertJsonPath('data.faceVerifiedAt', null);

    /* And the scan still works against them, later, from anywhere. */
    test()->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())
        ->assertSuccessful();

    expect($customer->refresh()->face_verified_at)->not->toBeNull();
});

it('keeps registration, face verification and approval as three separate facts', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    test()->postJson('/api/v1/customers', stepThreePayload())->assertCreated();
    $customer = Customer::query()->latest('id')->firstOrFail();

    /* Registered, unverified, unapproved — all three true at once, which is
       why collapsing them into one status would lose information. */
    expect($customer->approval_status)->toBe(CustomerApprovalStatus::Pending)
        ->and($customer->face_verified_at)->toBeNull();

    test()->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())
        ->assertSuccessful();

    /* Verifying a face approves nothing. The manager's decision is untouched
       by the scanner, and the existing approval workflow still governs
       eligibility. */
    expect($customer->refresh()->face_verified_at)->not->toBeNull()
        ->and($customer->approval_status)->toBe(CustomerApprovalStatus::Pending);
});

it('still persists marital status and residence type through the lifecycle', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    $married = App\Models\MasterData\MaritalStatusOption::query()->where('code', 'MARRIED')->firstOrFail();

    $payload = stepThreePayload(['maritalStatusId' => $married->getKey(), 'residenceType' => 'rented']);
    unset($payload['maritalStatus']);

    test()->postJson('/api/v1/customers', $payload)->assertCreated();
    $customer = Customer::query()->latest('id')->firstOrFail();

    test()->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())
        ->assertSuccessful();

    /* The demographic fix from the previous pass, re-asserted after the scan —
       a verification must not disturb what registration recorded. */
    test()->getJson("/api/v1/customers/{$customer->id}")
        ->assertOk()
        ->assertJsonPath('data.maritalStatus', 'married')
        ->assertJsonPath('data.residenceType', 'rented');
});

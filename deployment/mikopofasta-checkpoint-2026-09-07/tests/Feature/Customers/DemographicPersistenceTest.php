<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Models\Customer;
use App\Models\MasterData\MaritalStatusOption;

/**
 * Marital status and residence type, from the form to the profile.
 *
 * WHAT WENT WRONG. Both had a complete backend: a column, a cast, a fillable
 * entry, a validation rule, a write in the registration action and a key in the
 * API resource. Neither was ever SET.
 *
 * Residence type had no control on the form at all — the profile promised a
 * field nothing filled in. Marital status had one, but it wrote
 * `marital_status_id`, the admin-managed list's key, while the profile read
 * `marital_status`, the older enum column. One fact, two columns, and the form
 * filled the one nobody read. Both displayed as a dash on every customer.
 *
 * These assert the whole lifecycle rather than any one layer, because every
 * individual layer was already correct.
 */
beforeEach(function (): void {
    seedCustomerFoundation();
});

it('stores the residence type the form now asks for, and serves it back', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    test()->postJson('/api/v1/customers', registrationPayload(['residenceType' => 'rented']))
        ->assertCreated()
        ->assertJsonPath('data.residenceType', 'rented');

    expect(Customer::query()->latest('id')->firstOrFail()->residence_type->value)->toBe('rented');
});

it('mirrors the chosen marital status onto the column the profile reads', function (): void {
    $married = MaritalStatusOption::query()->where('code', 'MARRIED')->firstOrFail();

    officerAt('Kakonko', RoleName::LoanOfficer);

    $payload = registrationPayload(['maritalStatusId' => $married->getKey()]);
    /* The form no longer sends the enum — it asks through the admin-managed
       list, which is the whole point of the bug this guards. */
    unset($payload['maritalStatus']);

    test()->postJson('/api/v1/customers', $payload)->assertCreated();

    $customer = Customer::query()->latest('id')->firstOrFail();

    expect($customer->marital_status_id)->toBe($married->getKey())
        /* Both columns, agreeing. */
        ->and($customer->marital_status->value)->toBe('married');
});

it('matches on the code, so renaming the list entry changes nothing stored', function (): void {
    $married = MaritalStatusOption::query()->where('code', 'MARRIED')->firstOrFail();
    $married->update(['name' => 'Ndoa']);

    officerAt('Kakonko', RoleName::LoanOfficer);

    $payload = registrationPayload(['maritalStatusId' => $married->getKey()]);
    unset($payload['maritalStatus']);

    test()->postJson('/api/v1/customers', $payload)->assertCreated();

    expect(Customer::query()->latest('id')->firstOrFail()->marital_status->value)->toBe('married');
});

it('leaves the enum null for a status the column cannot express', function (): void {
    /* An institution may add one tomorrow. The enum's four values are fixed by
       a migration and cannot grow to meet it, so null is the truthful answer —
       and the profile names the chosen list entry instead of inventing one. */
    $separated = MaritalStatusOption::query()->create([
        'code' => 'SEPARATED', 'name' => 'Separated', 'is_active' => true,
    ]);

    officerAt('Kakonko', RoleName::LoanOfficer);

    $payload = registrationPayload(['maritalStatusId' => $separated->getKey()]);
    unset($payload['maritalStatus']);

    test()->postJson('/api/v1/customers', $payload)->assertCreated();

    $customer = Customer::query()->latest('id')->firstOrFail();

    expect($customer->marital_status)->toBeNull()
        /* Not lost — the answer is still on the record, and readable. */
        ->and($customer->marital_status_id)->toBe($separated->getKey());
});

it('keeps both columns in step when the profile is edited', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    $payload = registrationPayload();
    unset($payload['maritalStatus']);
    test()->postJson('/api/v1/customers', $payload)->assertCreated();

    $customer = Customer::query()->latest('id')->firstOrFail();
    $divorced = MaritalStatusOption::query()->where('code', 'DIVORCED')->firstOrFail();

    test()->putJson("/api/v1/customers/{$customer->id}", [
        'maritalStatusId' => $divorced->getKey(),
        'residenceType' => 'owned',
    ])->assertOk()
        ->assertJsonPath('data.maritalStatus', 'divorced')
        ->assertJsonPath('data.residenceType', 'owned');

    expect($customer->refresh()->marital_status->value)->toBe('divorced');
});

it('leaves an untouched customer alone when the update says nothing about it', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    $married = MaritalStatusOption::query()->where('code', 'MARRIED')->firstOrFail();
    $payload = registrationPayload(['maritalStatusId' => $married->getKey()]);
    unset($payload['maritalStatus']);
    test()->postJson('/api/v1/customers', $payload)->assertCreated();

    $customer = Customer::query()->latest('id')->firstOrFail();

    /* An edit to something else must not quietly clear an answer it never
       mentioned — the mirror is keyed on presence, not on absence. */
    test()->putJson("/api/v1/customers/{$customer->id}", ['landmark' => 'Near the market'])->assertOk();

    expect($customer->refresh()->marital_status->value)->toBe('married')
        ->and($customer->marital_status_id)->toBe($married->getKey());
});

it('reports nothing for a customer who was never asked', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    $payload = registrationPayload();
    unset($payload['maritalStatus'], $payload['maritalStatusId'], $payload['residenceType']);

    test()->postJson('/api/v1/customers', $payload)->assertCreated()
        /* A dash on the profile is correct here. Nothing is fabricated. */
        ->assertJsonPath('data.maritalStatus', null)
        ->assertJsonPath('data.residenceType', null);
});

<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Models\Branch;

/**
 * A resumed draft must come back in the shape it went out in.
 *
 * THE BUG THIS GUARDS. The wizard's `dynamicFormData` is a record. PHP has one
 * array type and JSON has two containers, so an empty record sent to this
 * endpoint decodes to an empty PHP array and re-encodes as `[]`. Resuming such
 * a draft handed the wizard an array where its schema demands a record, and the
 * registration was refused with "expected record, received array".
 *
 * It failed only when the record was EMPTY — which is the ordinary case, since a
 * well-configured customer type writes its answers to real columns and leaves
 * this map untouched — so it hid behind every draft that happened to carry data.
 */
beforeEach(function (): void {
    seedCustomerFoundation();
});

/** Saves a draft carrying the given payload and reads it back. */
function savedDraftPayload(array $payload): string
{
    officerAt('Kakonko', RoleName::LoanOfficer);

    $id = test()->postJson('/api/v1/customer-drafts', [
        'branchId' => Branch::query()->where('name', 'Kakonko')->value('id'),
        'label' => 'Shape test',
        'step' => 1,
        'payload' => $payload,
    ])->assertSuccessful()->json('data.id');

    /* The raw body, not the decoded array — the whole question is which JSON
       container the client receives, and decoding it here would erase the
       distinction exactly as PHP erased it on the way in. */
    return test()->getJson("/api/v1/customer-drafts/{$id}")->assertOk()->getContent();
}

it('returns an empty dynamic form record as an object, not an array', function (): void {
    $body = savedDraftPayload(['firstName' => 'Conrad', 'dynamicFormData' => []]);

    expect($body)->toContain('"dynamicFormData":{}')
        ->and($body)->not->toContain('"dynamicFormData":[]');
});

it('returns a populated dynamic form record unchanged', function (): void {
    $body = savedDraftPayload(['dynamicFormData' => ['land_size' => 4, 'crop' => 'maize']]);

    /* Content, not key order: a JSON object is unordered and the round trip
       through storage is free to reorder it. */
    expect(json_decode($body, true)['data']['payload']['dynamicFormData'])
        ->toEqualCanonicalizing(['land_size' => 4, 'crop' => 'maize'])
        ->and($body)->toContain('"land_size":4');
});

it('leaves the payload\'s genuine lists as lists', function (): void {
    /*
     * The other half of the rule. Forcing every empty array to an object would
     * fix the record and break `guarantors`, which is a list and is empty just
     * as often — which is why the resource names the record keys instead of
     * guessing from the contents.
     */
    $body = savedDraftPayload(['guarantors' => [], 'nextOfKin' => [], 'dynamicFormData' => []]);

    expect($body)->toContain('"guarantors":[]')
        ->and($body)->toContain('"nextOfKin":[]')
        ->and($body)->toContain('"dynamicFormData":{}');
});

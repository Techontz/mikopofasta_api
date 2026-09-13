<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Customers\Services\KycDocumentStorage;
use App\Domain\Customers\Services\KycEvaluator;
use App\Models\Customer;
use App\Models\MasterData\DocumentType;
use App\Models\MasterData\IdType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * WHICH document was seen, WHAT it says, and WHERE that pair goes.
 *
 * THE BUG THESE TESTS EXIST FOR. An officer chose an ID type and typed its
 * number; step one accepted both; and the browser's request builder — a
 * hand-maintained allowlist of fields to send — did not mention either of them.
 * They were dropped on the way out, the API refused the registration for
 * carrying no identity document, and the wizard sent the officer back to a step
 * where the fields were visibly filled in. Seven fields were affected, not two:
 * the sector, the cadre, the employer, the contract type and its expiry went
 * the same way, which meant a configured customer type could ask for them and
 * never receive them.
 *
 * The fix that matters is not in this file — it is the type annotation on that
 * request body, which now fails the build if a field of the contract is not
 * mentioned. What is tested here is the CONTRACT the browser must satisfy: the
 * pair is accepted, it is stored, each half is required without the other, and
 * the document an ID type calls for is data rather than a rule anybody wrote.
 *
 * Nothing here names NIDA, a passport or a voter card except as rows it creates
 * itself. The mapping is a column an administrator fills in.
 */
beforeEach(function (): void {
    seedCustomerFoundation();
});

/** An ID type linked to a freshly made document type. Both meaningless on purpose. */
function linkedIdType(string $suffix): array
{
    $document = DocumentType::query()->create([
        'code' => 'doc_'.$suffix,
        'name' => 'Document '.strtoupper($suffix),
        'is_active' => true,
    ]);

    $idType = IdType::query()->create([
        'code' => 'IDT_'.strtoupper($suffix),
        'name' => 'Identity Type '.strtoupper($suffix),
        'document_type_id' => $document->getKey(),
        'is_active' => true,
    ]);

    return [$idType, $document];
}

/* -------------------------------------------------------------------------
 | Scenario A — the pair is accepted and stored
 |------------------------------------------------------------------------- */

it('registers a customer whose identity is an ID type and its number', function (): void {
    [$idType] = linkedIdType('a');

    officerAt('Kakonko', RoleName::LoanOfficer);

    $payload = registrationPayload();
    /* No legacy number anywhere — the pair alone must carry it. */
    unset($payload['nidaNumber']);
    $payload['idTypeId'] = $idType->getKey();
    $payload['idNumber'] = '19980212898765678987';

    test()->postJson('/api/v1/customers', $payload)->assertCreated();

    $customer = Customer::query()->latest('id')->firstOrFail();

    expect($customer->id_type_id)->toBe($idType->getKey())
        ->and($customer->id_number)->toBe('19980212898765678987');
});

/* -------------------------------------------------------------------------
 | Scenarios D and E — each half is required without the other
 |------------------------------------------------------------------------- */

it('refuses an ID type with no number', function (): void {
    [$idType] = linkedIdType('d');

    officerAt('Kakonko', RoleName::LoanOfficer);

    $payload = registrationPayload();
    unset($payload['nidaNumber']);
    $payload['idTypeId'] = $idType->getKey();

    test()->postJson('/api/v1/customers', $payload)->assertStatus(422);
});

it('refuses a number with no ID type', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    $payload = registrationPayload();
    unset($payload['nidaNumber']);
    $payload['idNumber'] = '19980212898765678987';

    test()->postJson('/api/v1/customers', $payload)->assertStatus(422);
});

it('refuses a registration carrying no identity at all', function (): void {
    officerAt('Kakonko', RoleName::LoanOfficer);

    $payload = registrationPayload();
    unset($payload['nidaNumber']);

    test()->postJson('/api/v1/customers', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('idTypeId');
});

/* -------------------------------------------------------------------------
 | Scenarios B and C — the document an ID type calls for is DATA
 |------------------------------------------------------------------------- */

it('serves the document each identity type calls for', function (): void {
    [$first, $firstDocument] = linkedIdType('b');
    [$second, $secondDocument] = linkedIdType('c');

    officerAt('Kakonko', RoleName::LoanOfficer);

    $rows = collect(test()->getJson('/api/v1/master-data/id-types?active=1')->assertOk()->json('data'));

    expect($rows->firstWhere('id', (string) $first->getKey())['documentTypeId'])
        ->toBe((string) $firstDocument->getKey())
        /* A different identity type asks for a different document. The wizard
           resolves the slot from this, so choosing the second never leaves the
           first one's document on screen. */
        ->and($rows->firstWhere('id', (string) $second->getKey())['documentTypeId'])
        ->toBe((string) $secondDocument->getKey())
        ->and($firstDocument->getKey())->not->toBe($secondDocument->getKey());
});

it('reports no document for an identity type the institution takes no copy of', function (): void {
    $idType = IdType::query()->create(['code' => 'IDT_NOCOPY', 'name' => 'Seen, not copied', 'is_active' => true]);

    officerAt('Kakonko', RoleName::LoanOfficer);

    $rows = collect(test()->getJson('/api/v1/master-data/id-types?active=1')->assertOk()->json('data'));

    /* Null, not absent and not a guess. The documents step then shows the
       customer type's list alone. */
    expect($rows->firstWhere('id', (string) $idType->getKey())['documentTypeId'])->toBeNull();
});

/* -------------------------------------------------------------------------
 | The link is the administrator's, and it is theirs to change
 |------------------------------------------------------------------------- */

it('lets an administrator set which document proves an identity type', function (): void {
    $idType = IdType::query()->create(['code' => 'IDT_ADMIN', 'name' => 'Admin Set', 'is_active' => true]);
    $document = DocumentType::query()->create(['code' => 'doc_admin', 'name' => 'Admin Document', 'is_active' => true]);

    officerAt('Kakonko', RoleName::Admin);

    test()->putJson("/api/v1/master-data/id-types/{$idType->getKey()}", [
        'code' => $idType->code,
        'name' => $idType->name,
        'documentTypeId' => $document->getKey(),
    ])->assertOk()->assertJsonPath('data.documentTypeId', (string) $document->getKey());

    expect($idType->refresh()->document_type_id)->toBe($document->getKey());
});

it('refuses a link to a document type that does not exist', function (): void {
    $idType = IdType::query()->create(['code' => 'IDT_BAD', 'name' => 'Bad Link', 'is_active' => true]);

    officerAt('Kakonko', RoleName::Admin);

    test()->putJson("/api/v1/master-data/id-types/{$idType->getKey()}", [
        'code' => $idType->code,
        'name' => $idType->name,
        'documentTypeId' => 999999,
    ])->assertStatus(422)->assertJsonValidationErrors('documentTypeId');
});

it('stops asking for an upload when the document is retired, and keeps the identity type', function (): void {
    [$idType, $document] = linkedIdType('e');

    /*
     * A document type is SOFT-deleted — every lookup list in this system is,
     * because a row a customer record points at must stay readable. So the
     * foreign key's `nullOnDelete` never fires and the link survives in the
     * column, which is the right outcome: nothing about the customers already
     * filed under it changes.
     *
     * What changes is what the FORM sees. The retired document stops being
     * served to it, so the identity slot cannot be resolved and simply does not
     * appear — the same as an identity type nobody linked. The registration
     * still asks for the type and the number.
     */
    $document->delete();

    officerAt('Kakonko', RoleName::LoanOfficer);

    $documents = collect(test()->getJson('/api/v1/master-data/document-types?active=1')->assertOk()->json('data'));
    $idTypes = collect(test()->getJson('/api/v1/master-data/id-types?active=1')->assertOk()->json('data'));

    expect($idType->refresh()->exists)->toBeTrue()
        ->and($idTypes->firstWhere('id', (string) $idType->getKey()))->not->toBeNull()
        /* The link is still recorded — and unresolvable, which is what makes
           the slot disappear rather than render with a blank name. */
        ->and($documents->firstWhere('id', (string) $document->getKey()))->toBeNull();
});

it('drops the link when the document is destroyed outright', function (): void {
    [$idType, $document] = linkedIdType('f');

    /* The database-level guarantee, as opposed to the application's ordinary
       soft delete: if the row really goes, the link goes with it and the
       identity type is left intact rather than pointing at nothing. */
    $document->forceDelete();

    expect($idType->refresh()->document_type_id)->toBeNull()
        ->and($idType->exists)->toBeTrue();
});

/* -------------------------------------------------------------------------
 | Scenario G — one document, two reasons to ask for it
 |------------------------------------------------------------------------- */

it('lets a customer type require the very document the identity type calls for', function (): void {
    [$idType, $document] = linkedIdType('g');

    officerAt('Kakonko', RoleName::SuperAdmin);

    /* The overlap is legal in the data — a public servant may well be asked for
       the same national ID that proves who they are. What must not happen is
       TWO upload boxes for it, and that is the documents step's job: the
       identity slot wins and the category's copy is filtered out. */
    $id = test()->postJson('/api/v1/customer-categories', [
        'name' => 'Overlapping '.fake()->unique()->numerify('####'),
        'requiredDocuments' => [$document->code],
    ])->assertCreated()->json('data.id');

    officerAt('Kakonko', RoleName::LoanOfficer);

    $payload = registrationPayload();
    unset($payload['nidaNumber']);
    $payload['customerCategoryId'] = $id;
    $payload['dynamicFormData'] = [];
    $payload['idTypeId'] = $idType->getKey();
    $payload['idNumber'] = 'OVERLAP-1';

    test()->postJson('/api/v1/customers', $payload)->assertCreated();

    $served = collect(test()->getJson('/api/v1/customer-categories')->assertOk()->json('data'))
        ->firstWhere('id', (string) $id);

    expect($served['requiredDocuments'])->toBe([$document->code]);
});

/* -------------------------------------------------------------------------
 | The copy of the identity document is REQUIRED, and the requirement is data
 |------------------------------------------------------------------------- */

/** One KYC requirement by key, for the customer as they stand. */
function kycItem(Customer $customer, string $key): App\Domain\Customers\DTOs\KycRequirement
{
    $customer->load(['documents', 'idType', 'category']);

    foreach (app(KycEvaluator::class)->requirements($customer) as $requirement) {
        if ($requirement->key === $key) {
            return $requirement;
        }
    }

    throw new RuntimeException("No KYC requirement keyed {$key}.");
}

/** Registers a customer holding the given ID type, through the API. */
function customerWithIdType(IdType $idType, ?string $categoryId = null): Customer
{
    officerAt('Kakonko', RoleName::LoanOfficer);

    $payload = registrationPayload();
    unset($payload['nidaNumber']);
    $payload['idTypeId'] = $idType->getKey();
    $payload['idNumber'] = 'ID-'.fake()->unique()->numerify('########');

    if ($categoryId !== null) {
        $payload['customerCategoryId'] = $categoryId;
        $payload['dynamicFormData'] = [];
    }

    test()->postJson('/api/v1/customers', $payload)->assertCreated();

    return Customer::query()->latest('id')->firstOrFail();
}

it('counts the ID type and its number as an identity document', function (): void {
    [$idType] = linkedIdType('kyc1');

    $customer = customerWithIdType($idType);

    /*
     * The regression this guards. The checklist read only the six pre-2026_08_30
     * columns, so a customer registered through the current form — which
     * captures an ID type and a number and nothing else — was reported as
     * having no identity document at all. KYC never completed and every loan
     * application from them was refused KYC_INCOMPLETE.
     */
    $item = kycItem($customer, 'identityDocument');

    expect($item->satisfied)->toBeTrue()
        ->and($item->detail)->toContain($idType->name);
});

it('requires a copy of the document the ID type calls for, and is not satisfied without it', function (): void {
    [$idType, $document] = linkedIdType('kyc2');

    $customer = customerWithIdType($idType);

    $item = kycItem($customer, 'identityDocumentFile');

    expect($item->required)->toBeTrue()
        ->and($item->satisfied)->toBeFalse()
        ->and($item->detail)->toContain($document->name);
});

it('is satisfied once the document is uploaded, and the file is really on the disk', function (): void {
    Storage::fake(KycDocumentStorage::DISK);

    [$idType, $document] = linkedIdType('kyc3');

    $customer = customerWithIdType($idType);

    test()->postJson("/api/v1/customers/{$customer->id}/documents", [
        'documentType' => $document->code,
        'file' => UploadedFile::fake()->create('identity.pdf', 120, 'application/pdf'),
    ])->assertCreated();

    /* The row, and the bytes. A test that only checked the row would pass on a
       system that recorded the upload and stored nothing. */
    $stored = $customer->documents()->sole();

    expect($stored->document_type)->toBe($document->code);
    Storage::disk(KycDocumentStorage::DISK)->assertExists($stored->file_path);

    expect(kycItem($customer, 'identityDocumentFile')->satisfied)->toBeTrue();
});

it('demands nothing when the institution takes no copy of that identity type', function (): void {
    $idType = IdType::query()->create(['code' => 'IDT_KYC4', 'name' => 'Seen only', 'is_active' => true]);

    $customer = customerWithIdType($idType);

    /* An administrator decision, not a gap: the link is empty, so no copy is
       demanded and the requirement is satisfied rather than pending. */
    $item = kycItem($customer, 'identityDocumentFile');

    expect($item->required)->toBeFalse()->and($item->satisfied)->toBeTrue();
});

it('follows the mapping when an administrator changes it', function (): void {
    [$idType] = linkedIdType('kyc5');

    $customer = customerWithIdType($idType);

    $replacement = DocumentType::query()->create([
        'code' => 'doc_replacement', 'name' => 'Replacement Document', 'is_active' => true,
    ]);
    $idType->update(['document_type_id' => $replacement->getKey()]);

    /* Nothing was deployed and no customer was edited — the requirement moved
       because the configuration moved. */
    expect(kycItem($customer->refresh(), 'identityDocumentFile')->detail)
        ->toContain($replacement->name);
});

it('lets one upload satisfy both the identity requirement and the customer type list', function (): void {
    Storage::fake(KycDocumentStorage::DISK);

    [$idType, $document] = linkedIdType('kyc6');

    officerAt('Kakonko', RoleName::SuperAdmin);

    $categoryId = test()->postJson('/api/v1/customer-categories', [
        'name' => 'Overlap KYC '.fake()->unique()->numerify('####'),
        'requiredDocuments' => [$document->code],
    ])->assertCreated()->json('data.id');

    $customer = customerWithIdType($idType, $categoryId);

    test()->postJson("/api/v1/customers/{$customer->id}/documents", [
        'documentType' => $document->code,
        'file' => UploadedFile::fake()->create('identity.pdf', 120, 'application/pdf'),
    ])->assertCreated();

    $customer->refresh()->load(['documents', 'category', 'idType']);

    /*
     * ONE file, TWO requirements met. Both are matched on the document's code,
     * so the officer is never asked to upload the same physical document twice
     * — which is also why the documents step shows a single slot for it.
     */
    expect($customer->documents)->toHaveCount(1)
        ->and(app(KycEvaluator::class)->missingDocuments($customer))->toBe([])
        ->and(kycItem($customer, 'identityDocumentFile')->satisfied)->toBeTrue();
});

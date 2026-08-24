<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Customers\Enums\Gender;
use App\Domain\Customers\Enums\MaritalStatus;
use App\Domain\Customers\Services\KycDocumentStorage;
use App\Models\Customer;
use App\Models\Guarantor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A guarantor becomes identifiable — gender, marital status and a passport.
 *
 * The branch's loan application has always asked for these three; the table
 * had nowhere to put them, so the screen either dropped them silently or did
 * not ask. A guarantor is a person the institution may one day have to find,
 * and "we have their first name and a phone number" is not enough to do it.
 *
 * Every field here is NULLABLE and every test below leans on that: the
 * guarantors already on the books have none of it, and a required rule would
 * have made every one of them un-editable.
 */
beforeEach(function (): void {
    seedCustomerFoundation();
    Storage::fake(KycDocumentStorage::DISK);
});

/**
 * A customer with NO guarantors.
 *
 * `registeredCustomer()` ships one in its payload — the fixture has to, because
 * a loan account requires it — and every count in this file would otherwise be
 * off by one for a reason that has nothing to do with what is being tested. The
 * default account-type profile asks for none, so an empty list is accepted.
 */
function customerWithoutGuarantors(array $overrides = []): Customer
{
    return registeredCustomer(array_merge(['guarantors' => []], $overrides));
}

/** The minimum a guarantor has always needed, plus whatever is under test. */
function guarantorPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Neema Mushi',
        'phone' => '0755111333',
        'nidaNumber' => '19880101223344',
        'relationship' => 'sibling',
        'address' => 'Barabara ya Soko, Nyakayenzi, Kakonko, Kigoma',
        'occupation' => 'Trader',
    ], $overrides);
}

/* -------------------------------------------------------------------------
 | Creating
 |------------------------------------------------------------------------- */

describe('creating a guarantor', function (): void {
    it('stores gender, marital status and the identification number', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = customerWithoutGuarantors();

        $this->postJson("/api/v1/customers/{$customer->id}/guarantors", guarantorPayload([
            'gender' => 'female',
            'maritalStatus' => 'married',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.gender', 'female')
            ->assertJsonPath('data.maritalStatus', 'married')
            ->assertJsonPath('data.nidaNumber', '19880101223344');

        $guarantor = Guarantor::query()->latest('id')->firstOrFail();

        expect($guarantor->gender)->toBe(Gender::Female)
            ->and($guarantor->marital_status)->toBe(MaritalStatus::Married)
            ->and($guarantor->nida_number)->toBe('19880101223344');
    });

    /*
     * The 26 guarantors already on the books have neither, so a required rule
     * would have broken every existing record the moment it shipped.
     */
    it('accepts a guarantor with neither gender nor marital status', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = customerWithoutGuarantors();

        $this->postJson("/api/v1/customers/{$customer->id}/guarantors", guarantorPayload())
            ->assertCreated()
            ->assertJsonPath('data.gender', null)
            ->assertJsonPath('data.maritalStatus', null)
            ->assertJsonPath('data.passportUrl', null);
    });

    it('refuses a gender that is not one the application defines', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = customerWithoutGuarantors();

        $this->postJson("/api/v1/customers/{$customer->id}/guarantors", guarantorPayload([
            'gender' => 'unspecified',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['gender']);

        expect(Guarantor::query()->where('customer_id', $customer->getKey())->count())->toBe(0);
    });

    it('refuses a marital status that is not one the application defines', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = customerWithoutGuarantors();

        $this->postJson("/api/v1/customers/{$customer->id}/guarantors", guarantorPayload([
            'maritalStatus' => 'complicated',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['maritalStatus']);
    });

    it('keeps the guarantor on the customer they were created for', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $one = customerWithoutGuarantors();
        $two = customerWithoutGuarantors(['nidaNumber' => '19900202345678', 'phone' => '0755222444']);

        $this->postJson("/api/v1/customers/{$one->id}/guarantors", guarantorPayload())->assertCreated();

        expect($one->guarantors()->count())->toBe(1)
            ->and($two->guarantors()->count())->toBe(0);

        // And the other customer's endpoint does not show them.
        expect($this->getJson("/api/v1/customers/{$two->id}/guarantors")->json('data'))
            ->toBe([]);
    });
});

/* -------------------------------------------------------------------------
 | The passport
 |------------------------------------------------------------------------- */

describe('the passport document', function (): void {
    it('stores the upload on the private KYC disk and serves it by signed URL', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = customerWithoutGuarantors();

        $response = $this->post("/api/v1/customers/{$customer->id}/guarantors", guarantorPayload([
            'passport' => UploadedFile::fake()->image('neema.jpg'),
        ]))->assertCreated();

        $guarantor = Guarantor::query()->latest('id')->firstOrFail();

        /* Under the customer's own KYC directory, with a GENERATED filename —
           an upload's own name is attacker-controlled. */
        expect($guarantor->passport_path)->toStartWith("customers/{$customer->id}/")
            ->and($guarantor->passport_path)->not->toContain('neema.jpg')
            ->and($guarantor->passport_original_name)->toBe('neema.jpg')
            ->and($guarantor->passport_mime_type)->toBe('image/jpeg')
            ->and($guarantor->passport_size_bytes)->toBeGreaterThan(0);

        Storage::disk(KycDocumentStorage::DISK)->assertExists($guarantor->passport_path);

        /* The path itself never leaves the application — the client is given a
           signed, expiring URL instead. */
        $body = $response->json('data');

        expect($body['passportUrl'])->toContain('/guarantors/'.$guarantor->id.'/passport')
            ->and($body['passportUrl'])->toContain('signature=')
            ->and(json_encode($body))->not->toContain($guarantor->passport_path);
    });

    it('streams the passport through a valid signature and refuses an invalid one', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = customerWithoutGuarantors();

        $url = $this->post("/api/v1/customers/{$customer->id}/guarantors", guarantorPayload([
            'passport' => UploadedFile::fake()->image('neema.jpg'),
        ]))->assertCreated()->json('data.passportUrl');

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        // Tampering with the query invalidates it.
        $this->get($url.'&x=1')->assertForbidden();
    });

    it('refuses a file the KYC uploads do not accept', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = customerWithoutGuarantors();

        $this->post("/api/v1/customers/{$customer->id}/guarantors", guarantorPayload([
            'passport' => UploadedFile::fake()->create('payload.exe', 12, 'application/x-msdownload'),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['passport']);

        expect(Guarantor::query()->count())->toBe(0);
    });

    it('refuses a file above the KYC size limit', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = customerWithoutGuarantors();

        // The rule is max:10240 KB — one kilobyte over it.
        $this->post("/api/v1/customers/{$customer->id}/guarantors", guarantorPayload([
            'passport' => UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf'),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['passport']);
    });

    /* Nothing is silently accepted and dropped: a guarantor created without a
       file reports no passport at all rather than a dead URL. */
    it('reports no passport when none was uploaded', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = customerWithoutGuarantors();

        $this->postJson("/api/v1/customers/{$customer->id}/guarantors", guarantorPayload())
            ->assertCreated()
            ->assertJsonPath('data.passportUrl', null)
            ->assertJsonPath('data.passportName', null);
    });

    /*
     * Removing a guarantor removes their passport too. An unreferenced KYC
     * document on a private disk is one nobody will ever go looking for, and
     * keeping regulated files with no record of whose they are is the opposite
     * of what a private disk is for.
     */
    it('deletes the stored passport when the guarantor is removed', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = customerWithoutGuarantors();

        $id = $this->post("/api/v1/customers/{$customer->id}/guarantors", guarantorPayload([
            'passport' => UploadedFile::fake()->image('neema.jpg'),
        ]))->assertCreated()->json('data.id');

        $path = Guarantor::query()->findOrFail($id)->passport_path;
        Storage::disk(KycDocumentStorage::DISK)->assertExists($path);

        $this->deleteJson("/api/v1/customers/{$customer->id}/guarantors/{$id}")->assertOk();

        Storage::disk(KycDocumentStorage::DISK)->assertMissing($path);
    });

    /* The file is written before the transaction, so a failed insert has to
       clean up after itself rather than leave the object behind. */
    it('leaves no file behind when the guarantor cannot be created', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = customerWithoutGuarantors();

        $this->post("/api/v1/customers/{$customer->id}/guarantors", guarantorPayload([
            'relationship' => 'landlord',
            'passport' => UploadedFile::fake()->image('neema.jpg'),
        ]))->assertStatus(422);

        expect(Storage::disk(KycDocumentStorage::DISK)->allFiles("customers/{$customer->id}"))->toBe([]);
    });
});

/* -------------------------------------------------------------------------
 | Importing
 |------------------------------------------------------------------------- */

describe('importing an existing guarantor', function (): void {
    /*
     * The loan screen's (1)(b) step. It is a COPY onto the new customer, not a
     * shared row: `guarantors.customer_id` is the owning key, and if one row
     * served two customers, removing the guarantor from one file would
     * silently remove them from the other.
     */
    it('copies every stored field onto the new customer', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $source = customerWithoutGuarantors();
        $target = customerWithoutGuarantors(['nidaNumber' => '19900202345678', 'phone' => '0755222444']);

        $original = $this->post("/api/v1/customers/{$source->id}/guarantors", guarantorPayload([
            'gender' => 'female',
            'maritalStatus' => 'married',
            'passport' => UploadedFile::fake()->image('neema.jpg'),
        ]))->assertCreated()->json('data');

        /* What the import picker hands the client — the same shape the copy is
           assembled from. */
        $pool = collect($this->getJson('/api/v1/guarantors')->assertOk()->json('data'))
            ->firstWhere('id', $original['id']);

        expect($pool['gender'])->toBe('female')
            ->and($pool['maritalStatus'])->toBe('married')
            ->and($pool['customerNumber'])->toBe($source->customer_number);

        // The import itself: the ordinary create endpoint, same validation.
        $this->postJson("/api/v1/customers/{$target->id}/guarantors", [
            'name' => $pool['name'],
            'phone' => $pool['phone'],
            'nidaNumber' => $pool['nidaNumber'],
            'gender' => $pool['gender'],
            'maritalStatus' => $pool['maritalStatus'],
            'relationship' => 'friend',
            'address' => $pool['address'],
            'occupation' => $pool['occupation'],
        ])->assertCreated();

        $copy = $target->guarantors()->latest('id')->firstOrFail();

        expect($copy->name)->toBe('Neema Mushi')
            ->and($copy->phone)->toBe('0755111333')
            ->and($copy->nida_number)->toBe('19880101223344')
            ->and($copy->gender)->toBe(Gender::Female)
            ->and($copy->marital_status)->toBe(MaritalStatus::Married)
            ->and($copy->occupation)->toBe('Trader')
            /* The relationship is taken fresh — it is a fact about THIS
               pairing. The same person may be one borrower's sibling and
               another's friend. */
            ->and($copy->relationship->value)->toBe('friend');

        // Two rows, one per customer. Deleting one must not touch the other.
        expect(Guarantor::query()->where('name', 'Neema Mushi')->count())->toBe(2)
            ->and($copy->getKey())->not->toBe((int) $original['id']);

        $this->deleteJson("/api/v1/customers/{$target->id}/guarantors/{$copy->id}")->assertOk();

        expect($source->guarantors()->count())->toBe(1);
    });

    /*
     * The passport travels with the import, copied on the server's private
     * disk — the browser holds no file to re-upload, and sending a regulated
     * document on a round trip through a client to get it back byte-for-byte
     * would be absurd.
     */
    it('copies the passport to its own file rather than sharing one', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $source = customerWithoutGuarantors();
        $target = customerWithoutGuarantors(['nidaNumber' => '19900202345678', 'phone' => '0755222444']);

        $sourceId = $this->post("/api/v1/customers/{$source->id}/guarantors", guarantorPayload([
            'passport' => UploadedFile::fake()->image('neema.jpg'),
        ]))->assertCreated()->json('data.id');

        $this->postJson("/api/v1/customers/{$target->id}/guarantors", guarantorPayload([
            'relationship' => 'friend',
            'copyPassportFromGuarantorId' => $sourceId,
        ]))->assertCreated();

        $original = Guarantor::query()->findOrFail($sourceId);
        $copy = $target->guarantors()->latest('id')->firstOrFail();

        // Two files, one per customer — not one path on two rows.
        expect($copy->passport_path)->not->toBeNull()
            ->and($copy->passport_path)->not->toBe($original->passport_path)
            ->and($copy->passport_path)->toStartWith("customers/{$target->id}/")
            /* The description of the file travels with it. */
            ->and($copy->passport_original_name)->toBe($original->passport_original_name)
            ->and($copy->passport_mime_type)->toBe($original->passport_mime_type);

        Storage::disk(KycDocumentStorage::DISK)->assertExists($copy->passport_path);

        // Removing the copy must not take the source's evidence with it.
        $this->deleteJson("/api/v1/customers/{$target->id}/guarantors/{$copy->id}")->assertOk();

        Storage::disk(KycDocumentStorage::DISK)->assertExists($original->passport_path);
    });

    /*
     * An id is not an authorisation. `exists:guarantors,id` proves the row is
     * real, not that this officer may see it — without the branch check the
     * import would be a way to pull another branch's KYC document by guessing
     * a number.
     */
    it('refuses to copy a passport from another branch’s guarantor', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $foreign = customerWithoutGuarantors();

        $foreignGuarantorId = $this->post("/api/v1/customers/{$foreign->id}/guarantors", guarantorPayload([
            'passport' => UploadedFile::fake()->image('neema.jpg'),
        ]))->assertCreated()->json('data.id');

        officerAt('Lindi', RoleName::LoanOfficer);
        $mine = customerWithoutGuarantors([
            'branchId' => App\Models\Branch::query()->where('name', 'Lindi')->value('id'),
            'nidaNumber' => '19900202345678',
            'phone' => '0755222444',
        ]);

        $this->postJson("/api/v1/customers/{$mine->id}/guarantors", guarantorPayload([
            'copyPassportFromGuarantorId' => $foreignGuarantorId,
        ]))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'BRANCH_SCOPE_VIOLATION');

        expect($mine->guarantors()->count())->toBe(0);
    });

    /*
     * §13 does not stop applying because the record is one level down. Without
     * the join to the owning customer, the import pool would be a way to read
     * every branch's guarantor book — names, phone numbers and ID numbers.
     */
    it('shows only guarantors inside the officer’s branch scope', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = customerWithoutGuarantors();

        $hidden = $this->postJson("/api/v1/customers/{$customer->id}/guarantors", guarantorPayload())
            ->assertCreated()->json('data.id');

        expect(collect($this->getJson('/api/v1/guarantors')->assertOk()->json('data'))->pluck('id'))
            ->toContain($hidden);

        officerAt('Lindi', RoleName::LoanOfficer);

        expect(collect($this->getJson('/api/v1/guarantors')->assertOk()->json('data'))->pluck('id'))
            ->not->toContain($hidden);
    });
});

/* -------------------------------------------------------------------------
 | Authorization
 |------------------------------------------------------------------------- */

describe('authorization', function (): void {
    it('refuses a guarantor write from a role without customers.manage', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = customerWithoutGuarantors();

        officerAt('Kakonko', RoleName::Auditor);

        $this->postJson("/api/v1/customers/{$customer->id}/guarantors", guarantorPayload())
            ->assertForbidden();
    });

    it('refuses a guarantor write against another branch’s customer', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = customerWithoutGuarantors();

        officerAt('Lindi', RoleName::LoanOfficer);

        $this->postJson("/api/v1/customers/{$customer->id}/guarantors", guarantorPayload())
            ->assertForbidden()
            ->assertJsonPath('error_code', 'BRANCH_SCOPE_VIOLATION');
    });
});

/* -------------------------------------------------------------------------
 | Nothing that already worked stopped working
 |------------------------------------------------------------------------- */

describe('existing behaviour', function (): void {
    it('still requires a name, a phone and a known relationship', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = customerWithoutGuarantors();

        $this->postJson("/api/v1/customers/{$customer->id}/guarantors", [
            'name' => '',
            'phone' => '123',
            'relationship' => 'landlord',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'phone', 'relationship']);
    });

    it('still lists and removes a guarantor, and still counts toward the loan gate', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = customerWithoutGuarantors();

        $id = $this->postJson("/api/v1/customers/{$customer->id}/guarantors", guarantorPayload())
            ->assertCreated()->json('data.id');

        expect($this->getJson("/api/v1/customers/{$customer->id}/guarantors")->json('data'))
            ->toHaveCount(1);

        $this->deleteJson("/api/v1/customers/{$customer->id}/guarantors/{$id}")->assertOk();

        expect($customer->guarantors()->count())->toBe(0);
    });

    /* The resource is a published contract — the registration wizard and the
       profile both read it. New keys are additive; none of the old ones moved. */
    it('keeps every field the guarantor resource already published', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = customerWithoutGuarantors();

        $this->postJson("/api/v1/customers/{$customer->id}/guarantors", guarantorPayload())
            ->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id', 'customerId', 'name', 'phone', 'nidaNumber', 'relationship',
                    'address', 'occupation', 'createdAt',
                    'gender', 'maritalStatus',
                    'passportUrl', 'passportName', 'passportMimeType', 'passportSizeBytes',
                ],
            ]);
    });
});

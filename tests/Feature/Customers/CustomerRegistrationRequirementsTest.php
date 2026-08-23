<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\PermissionName;
use App\Domain\Auth\Enums\RoleName;
use App\Domain\Customers\Enums\KycStatus;
use App\Models\AccountTypeRequirement;
use App\Models\Customer;
use App\Models\CustomerRegistrationDraft;
use App\Models\MasterData\AccountType;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * The registration workflow, end to end.
 *
 *     Basic → Additional → Identity → Bank → Save → (later, elsewhere) Face
 *
 * Three things are being proved here, and each of them was broken before:
 *
 *   1. WHAT A REGISTRATION MUST CARRY IS THE ACCOUNT TYPE'S DECISION, held in
 *      `account_type_requirements` and enforced by the API — not by the form.
 *   2. A REGISTRATION SAVES WITHOUT A FACE SCAN, and the customer is then
 *      correctly reported as awaiting one. The scan can be run afterwards, by
 *      a different user, from a different session.
 *   3. NIDA AND SMS BEING ABSENT DOES NOT BLOCK ANYTHING, and nothing anywhere
 *      claims either check ran.
 */
beforeEach(function (): void {
    seedCustomerFoundation();
});

/** The account type id for a shipped code, as the wizard would send it. */
function accountType(string $code): int
{
    return (int) AccountType::query()->where('code', $code)->value('id');
}

/* -------------------------------------------------------------------------
 | The requirements endpoint
 |------------------------------------------------------------------------- */

describe('registration requirements', function (): void {
    it('publishes a profile for every account type plus the default', function (): void {
        officerAt();

        $response = $this->getJson('/api/v1/registration/requirements')->assertOk();

        $profiles = collect($response->json('data.profiles'));

        // The default row first — the wizard reads it before an account type
        // has been chosen, which is the state the form opens in.
        expect($profiles->first()['isDefault'])->toBeTrue()
            ->and($profiles->firstWhere('accountTypeId', (string) accountType('LOAN')))
            ->toMatchArray([
                'requiresEmploymentDetails' => true,
                'requiresBankAccount' => true,
                'minGuarantors' => 1,
                'requiresCustomerCategory' => true,
            ])
            ->and($profiles->firstWhere('accountTypeId', (string) accountType('SAVINGS')))
            ->toMatchArray([
                'requiresEmploymentDetails' => false,
                'requiresBankAccount' => true,
                'minGuarantors' => 0,
            ]);
    });

    /*
     * The honesty requirement, asserted rather than assumed. If either of these
     * ever reports `available: true` without an integration behind it, the
     * whole "captured, not verified" distinction has quietly collapsed.
     */
    it('reports NIDA and SMS as unavailable, in words', function (): void {
        officerAt();

        $response = $this->getJson('/api/v1/registration/requirements')->assertOk();

        expect($response->json('data.externalVerification.nida.available'))->toBeFalse()
            ->and($response->json('data.externalVerification.nida.note'))
            ->toContain('not externally verified')
            ->and($response->json('data.externalVerification.otp.available'))->toBeFalse()
            ->and($response->json('data.externalVerification.otp.note'))
            ->toContain('no code is sent');
    });

    it('lets an administrator change what an account type requires', function (): void {
        officerAt(role: RoleName::Admin);

        $this->putJson('/api/v1/registration/requirements/'.accountType('SAVINGS'), [
            'requiresEmploymentDetails' => false,
            'requiresBusinessDetails' => false,
            'requiresBankAccount' => false,
            'requiresCardDetails' => false,
            'minGuarantors' => 0,
            'minNextOfKin' => 0,
            'requiresCustomerCategory' => false,
            'requiresMaritalStatus' => false,
            'requiresAddress' => true,
            'requiresIdentityDocument' => true,
            'requiresFaceVerification' => true,
            'requiresNidaVerification' => false,
            'requiresOtpVerification' => false,
            'guidance' => null,
        ])->assertOk()->assertJsonPath('data.requiresBankAccount', false);

        expect(
            AccountTypeRequirement::query()->where('account_type_id', accountType('SAVINGS'))->value('requires_bank_account'),
        )->toBeFalse();
    });

    it('refuses a requirement change without admin.org_settings', function (): void {
        officerAt();

        $this->putJson('/api/v1/registration/requirements/'.accountType('SAVINGS'), [
            'requiresEmploymentDetails' => false,
            'requiresBusinessDetails' => false,
            'requiresBankAccount' => false,
            'requiresCardDetails' => false,
            'minGuarantors' => 0,
            'minNextOfKin' => 0,
            'requiresCustomerCategory' => false,
            'requiresMaritalStatus' => false,
            'requiresAddress' => true,
            'requiresIdentityDocument' => true,
            'requiresFaceVerification' => true,
            'requiresNidaVerification' => false,
            'requiresOtpVerification' => false,
            'guidance' => null,
        ])->assertForbidden();
    });
});

/* -------------------------------------------------------------------------
 | Account type drives validation
 |------------------------------------------------------------------------- */

describe('account type requirements', function (): void {
    it('refuses a loan account missing the things a loan account needs', function (): void {
        officerAt();

        $payload = registrationPayload([
            'accountTypeId' => accountType('LOAN'),
            'guarantors' => [],
            'nextOfKin' => [],
            'customerCategoryId' => null,
            'maritalStatus' => null,
        ]);

        $this->postJson('/api/v1/customers', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'employer',
                'workType',
                'takeHome',
                'guarantors',
                'nextOfKin',
                'customerCategoryId',
                'maritalStatusId',
            ]);

        expect(Customer::query()->count())->toBe(0);
    });

    it('accepts the same customer once the loan account requirements are met', function (): void {
        officerAt();

        $this->postJson('/api/v1/customers', registrationPayload([
            'accountTypeId' => accountType('LOAN'),
            'employer' => 'Kakonko District Council',
            'workType' => 'Fundi wa pikipiki',
            'employmentType' => 'Government employee on contract',
            'takeHome' => 480000,
        ]))->assertCreated();

        $customer = Customer::query()->latest('id')->firstOrFail();

        // Both free-text, both stored as typed. No list contains either.
        expect($customer->work_type)->toBe('Fundi wa pikipiki')
            ->and($customer->employment_type)->toBe('Government employee on contract');
    });

    it('does not ask a savings account for an employer or a guarantor', function (): void {
        officerAt();

        $this->postJson('/api/v1/customers', registrationPayload([
            'accountTypeId' => accountType('SAVINGS'),
            'guarantors' => [],
            'nextOfKin' => [],
        ]))->assertCreated();
    });

    it('insists a savings account has somewhere to put the money', function (): void {
        officerAt();

        $this->postJson('/api/v1/customers', registrationPayload([
            'accountTypeId' => accountType('SAVINGS'),
            'bankDetails' => null,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bankDetails.accountNumber']);
    });

    /*
     * The exclusion this rule exists to avoid. A customer with no bank account
     * at all is the common case in this market, and a wallet is where their
     * money actually lives.
     */
    it('accepts a mobile money wallet in place of a bank account', function (): void {
        officerAt();

        $this->postJson('/api/v1/customers', registrationPayload([
            'accountTypeId' => accountType('SAVINGS'),
            'bankDetails' => null,
            'walletNumber' => '0754000321',
        ]))->assertCreated();
    });
});

/* -------------------------------------------------------------------------
 | Identity, without NIDA
 |------------------------------------------------------------------------- */

describe('identity capture', function (): void {
    it('accepts any one identity document', function (string $field, string $value): void {
        officerAt();

        $payload = registrationPayload();
        unset($payload['nidaNumber']);
        $payload[$field] = $value;

        $this->postJson('/api/v1/customers', $payload)->assertCreated();
    })->with([
        'voter card' => ['voterIdNumber', 'VTR-771234'],
        'driving licence' => ['driverLicenceNumber', 'DL-4410992'],
        'passport' => ['passportNumber', 'TZ0998812'],
        'work ID' => ['workIdNumber', 'EMP-3391'],
    ]);

    it('refuses a registration carrying no identity document at all', function (): void {
        officerAt();

        $payload = registrationPayload();
        unset($payload['nidaNumber']);

        $this->postJson('/api/v1/customers', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nationalIdNumber']);
    });

    /*
     * The single most important assertion in this file. A National ID typed by
     * an officer must never produce a verification timestamp, because nothing
     * checked it.
     */
    it('never marks a hand-entered identity as NIDA-verified', function (): void {
        officerAt();

        $this->postJson('/api/v1/customers', registrationPayload())->assertCreated();

        $customer = Customer::query()->latest('id')->firstOrFail();

        expect($customer->nida_verified_at)->toBeNull()
            ->and($customer->otp_verified_at)->toBeNull()
            ->and($customer->registration_source)->toBe('manual')
            ->and($customer->nida_number)->not->toBeNull();
    });

    it('does not let a missing NIDA integration block KYC completion', function (): void {
        officerAt();

        $this->postJson('/api/v1/customers', registrationPayload())->assertCreated();
        $customer = Customer::query()->latest('id')->firstOrFail();

        $status = $this->getJson("/api/v1/customers/{$customer->id}/kyc-status")->assertOk();

        // Reported, not required — and the note says why.
        $nida = collect($status->json('data.requirements'))->firstWhere('key', 'nidaVerified');

        expect($nida['required'])->toBeFalse()
            ->and($nida['blocked'])->toBeFalse()
            ->and($nida['detail'])->toContain('not externally verified')
            ->and($status->json('data.isComplete'))->toBeTrue();
    });

    /*
     * And the other direction: if somebody DOES require it, the customer stalls
     * visibly rather than silently. A dead end that says it is a dead end.
     */
    it('marks NIDA as blocked when required but unavailable', function (): void {
        officerAt();

        AccountTypeRequirement::query()->whereNull('account_type_id')
            ->update(['requires_nida_verification' => true]);

        $customer = registeredCustomer();

        $nida = collect(
            $this->getJson("/api/v1/customers/{$customer->id}/kyc-status")->json('data.requirements'),
        )->firstWhere('key', 'nidaVerified');

        expect($nida['blocked'])->toBeTrue()
            ->and($nida['required'])->toBeFalse();
    });
});

/* -------------------------------------------------------------------------
 | Address: chosen down to district, typed below it
 |------------------------------------------------------------------------- */

describe('address', function (): void {
    it('requires a district, not only a region', function (): void {
        officerAt();

        $this->postJson('/api/v1/customers', registrationPayload(['districtId' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['districtId'])
            ->assertJsonPath('errors.districtId.0', 'District must be selected.');
    });

    it('stores the ward and street as typed', function (): void {
        officerAt();

        $this->postJson('/api/v1/customers', registrationPayload([
            'wardName' => 'Nyakayenzi',
            'streetName' => 'Barabara ya Soko',
        ]))->assertCreated();

        $customer = Customer::query()->latest('id')->firstOrFail();

        expect($customer->ward_name)->toBe('Nyakayenzi')
            ->and($customer->street_name)->toBe('Barabara ya Soko')
            // No reference row was invented to hold them.
            ->and($customer->ward_id)->toBeNull()
            ->and($customer->street_id)->toBeNull();
    });

    it('does not require a ward or a street', function (): void {
        officerAt();

        $payload = registrationPayload();
        unset($payload['wardName'], $payload['streetName']);

        $this->postJson('/api/v1/customers', $payload)->assertCreated();
    });
});

/* -------------------------------------------------------------------------
 | Who the customer belongs to
 |------------------------------------------------------------------------- */

describe('assigned officer', function (): void {
    it('defaults the employee to whoever is registering', function (): void {
        $officer = officerAt();

        $payload = registrationPayload();
        unset($payload['employeeId']);

        $this->postJson('/api/v1/customers', $payload)->assertCreated();

        expect(Customer::query()->latest('id')->firstOrFail()->employee_id)
            ->toBe($officer->getKey());
    });

    it('refuses to register a customer under another officer without the grant', function (): void {
        officerAt();
        $colleague = User::factory()->role(RoleName::LoanOfficer)->create();

        $this->postJson('/api/v1/customers', registrationPayload(['employeeId' => $colleague->getKey()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['employeeId']);
    });

    it('allows it for someone holding customers.assign_officer', function (): void {
        $manager = officerAt(role: RoleName::BranchManager);
        $colleague = User::factory()->role(RoleName::LoanOfficer)->create([
            'branch_id' => $manager->branch_id,
        ]);

        expect($manager->hasPermission(PermissionName::CustomersAssignOfficer))->toBeTrue();

        $this->postJson('/api/v1/customers', registrationPayload(['employeeId' => $colleague->getKey()]))
            ->assertCreated();

        expect(Customer::query()->latest('id')->firstOrFail()->employee_id)
            ->toBe($colleague->getKey());
    });
});

/* -------------------------------------------------------------------------
 | Face verification as a separate, later, elsewhere step
 |------------------------------------------------------------------------- */

describe('face verification as the final step', function (): void {
    it('saves the registration and reports it awaiting a face scan', function (): void {
        officerAt();

        $payload = registrationPayload();
        unset($payload['faceVerifiedAt']);

        $this->postJson('/api/v1/customers', $payload)->assertCreated();
        $customer = Customer::query()->latest('id')->firstOrFail();

        $this->getJson("/api/v1/customers/{$customer->id}/kyc-status")
            ->assertOk()
            ->assertJsonPath('data.isComplete', false)
            ->assertJsonPath('data.progress.stage', 'awaiting_face_verification')
            ->assertJsonPath('data.isLoanEligible', false);

        // Findable in the ordinary list, not hidden in some pending limbo.
        $this->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonFragment(['id' => (string) $customer->getKey()]);
    });

    /*
     * The cross-device requirement, stated as it actually works: a different
     * user, authenticating separately, completes the scan. Nothing carries
     * over from the registering session — no browser state, no draft, no
     * open form.
     */
    it('lets a different user on a different session complete the scan', function (): void {
        Storage::fake('kyc');
        officerAt();

        $payload = registrationPayload();
        unset($payload['faceVerifiedAt']);
        $this->postJson('/api/v1/customers', $payload)->assertCreated();
        $customer = Customer::query()->latest('id')->firstOrFail();

        // A second user signs in — the registering officer's session is not
        // reused, and is no longer relevant.
        $other = User::factory()->role(RoleName::BranchManager)->create([
            'branch_id' => $customer->branch_id,
        ]);
        Sanctum::actingAs($other, ['*']);

        $this->post("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())
            ->assertOk();

        $customer->refresh();

        expect($customer->face_verified_at)->not->toBeNull()
            ->and($customer->face_scanned_by)->toBe($other->getKey())
            ->and($customer->kyc_status)->toBe(KycStatus::Completed);

        $this->getJson("/api/v1/customers/{$customer->id}/kyc-status")
            ->assertJsonPath('data.progress.stage', 'loan_eligible')
            ->assertJsonPath('data.isLoanEligible', true);
    });

    it('does not complete KYC on a failed scan', function (): void {
        Storage::fake('kyc');
        officerAt();

        $payload = registrationPayload();
        unset($payload['faceVerifiedAt']);
        $this->postJson('/api/v1/customers', $payload)->assertCreated();
        $customer = Customer::query()->latest('id')->firstOrFail();

        $this->post("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload([
            'status' => 'failed',
            'livenessPassed' => false,
            'checks' => ['liveness' => false],
        ]))->assertOk();

        expect($customer->refresh()->kyc_status)->toBe(KycStatus::Incomplete);

        $this->getJson("/api/v1/customers/{$customer->id}/kyc-status")
            ->assertJsonPath('data.progress.stage', 'awaiting_face_verification');
    });

    it('reports the outstanding item in words the officer can act on', function (): void {
        officerAt();

        $payload = registrationPayload();
        unset($payload['faceVerifiedAt']);
        $this->postJson('/api/v1/customers', $payload)->assertCreated();
        $customer = Customer::query()->latest('id')->firstOrFail();

        $progress = $this->getJson("/api/v1/customers/{$customer->id}/kyc-status")->json('data.progress');

        expect($progress['label'])->toBe('Awaiting face verification')
            ->and($progress['nextAction'])->toContain('Any signed-in device')
            ->and($progress['outstanding'])->toHaveCount(1);
    });
});

/* -------------------------------------------------------------------------
 | Save and resume
 |------------------------------------------------------------------------- */

describe('registration drafts', function (): void {
    it('saves a draft and reads it back', function (): void {
        $officer = officerAt();

        $created = $this->postJson('/api/v1/customer-drafts', [
            'branchId' => $officer->branch_id,
            'label' => 'Neema Juma',
            'phone' => '0755111222',
            'step' => 2,
            'payload' => ['firstName' => 'Neema', 'lastName' => 'Juma', 'guarantors' => []],
        ])->assertCreated();

        $id = $created->json('data.id');

        $this->getJson("/api/v1/customer-drafts/{$id}")
            ->assertOk()
            ->assertJsonPath('data.step', 2)
            ->assertJsonPath('data.payload.firstName', 'Neema');
    });

    /* One draft per registration, not one per browser. An officer interrupted
       on one customer and starting another must not lose the first. */
    it('keeps two open drafts side by side', function (): void {
        $officer = officerAt();

        foreach (['Neema Juma', 'Baraka Mushi'] as $label) {
            $this->postJson('/api/v1/customer-drafts', [
                'branchId' => $officer->branch_id,
                'label' => $label,
                'phone' => null,
                'step' => 0,
                'payload' => ['firstName' => $label],
            ])->assertCreated();
        }

        expect($this->getJson('/api/v1/customer-drafts')->json('data'))->toHaveCount(2);
    });

    it('overwrites the draft it is given rather than making a new one', function (): void {
        $officer = officerAt();

        $id = $this->postJson('/api/v1/customer-drafts', [
            'branchId' => $officer->branch_id,
            'label' => 'Neema',
            'phone' => null,
            'step' => 0,
            'payload' => ['firstName' => 'Neema'],
        ])->json('data.id');

        $this->postJson('/api/v1/customer-drafts', [
            'id' => (int) $id,
            'branchId' => $officer->branch_id,
            'label' => 'Neema Juma',
            'phone' => '0755111222',
            'step' => 3,
            'payload' => ['firstName' => 'Neema', 'lastName' => 'Juma'],
        ])->assertOk()->assertJsonPath('data.id', $id);

        expect(CustomerRegistrationDraft::query()->count())->toBe(1);
    });

    it('will not let one officer overwrite another officer’s draft', function (): void {
        $officer = officerAt();
        $id = $this->postJson('/api/v1/customer-drafts', [
            'branchId' => $officer->branch_id,
            'label' => 'Neema',
            'phone' => null,
            'step' => 0,
            'payload' => [],
        ])->json('data.id');

        $colleague = User::factory()->role(RoleName::LoanOfficer)->create([
            'branch_id' => $officer->branch_id,
        ]);
        Sanctum::actingAs($colleague, ['*']);

        $this->postJson('/api/v1/customer-drafts', [
            'id' => (int) $id,
            'branchId' => $officer->branch_id,
            'label' => 'Hijacked',
            'phone' => null,
            'step' => 0,
            'payload' => [],
        ])->assertForbidden();
    });

    it('closes the draft when the registration is submitted, and keeps the row', function (): void {
        $officer = officerAt();

        $draftId = $this->postJson('/api/v1/customer-drafts', [
            'branchId' => $officer->branch_id,
            'label' => 'Neema',
            'phone' => null,
            'step' => 4,
            'payload' => [],
        ])->json('data.id');

        $customer = registeredCustomer();

        $this->postJson("/api/v1/customer-drafts/{$draftId}/submitted", [
            'customerId' => $customer->getKey(),
        ])->assertOk();

        $draft = CustomerRegistrationDraft::query()->findOrFail($draftId);

        expect($draft->submitted_at)->not->toBeNull()
            ->and($draft->customer_id)->toBe($customer->getKey())
            // Closed, not deleted — the record of how long this took survives.
            ->and(CustomerRegistrationDraft::query()->count())->toBe(1);

        // And it drops out of the open list.
        expect($this->getJson('/api/v1/customer-drafts')->json('data'))->toHaveCount(0);
    });

    it('does not show a draft from a branch this officer cannot see', function (): void {
        $officer = officerAt();
        $this->postJson('/api/v1/customer-drafts', [
            'branchId' => $officer->branch_id,
            'label' => 'Kakonko draft',
            'phone' => null,
            'step' => 0,
            'payload' => [],
        ])->assertCreated();

        Sanctum::actingAs(
            User::factory()->role(RoleName::LoanOfficer)->create([
                'branch_id' => App\Models\Branch::query()->where('name', 'Lindi')->value('id'),
            ]),
            ['*'],
        );

        expect($this->getJson('/api/v1/customer-drafts')->json('data'))->toHaveCount(0);
    });

    it('lets the author discard their own draft', function (): void {
        $officer = officerAt();

        $id = $this->postJson('/api/v1/customer-drafts', [
            'branchId' => $officer->branch_id,
            'label' => 'Neema',
            'phone' => null,
            'step' => 0,
            'payload' => [],
        ])->json('data.id');

        $this->deleteJson("/api/v1/customer-drafts/{$id}")->assertOk();

        expect(CustomerRegistrationDraft::query()->count())->toBe(0);
    });
});

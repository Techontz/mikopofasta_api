<?php

declare(strict_types=1);

use App\Domain\Customers\Enums\KycStatus;
use App\Domain\Customers\Services\NidaRegistry;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerCategory;

describe('NIDA identity flow', function (): void {
    beforeEach(function (): void {
        seedCustomerFoundation();
    });

    it('resolves an identity and reports the OTP as sent', function (): void {
        officerAt();

        $response = $this->postJson('/api/v1/customers/nida-lookup', ['nidaNumber' => '19900101234567']);

        $response->assertOk()
            ->assertJsonPath('data.otpSent', true)
            ->assertJsonStructure(['data' => ['verified', 'otpSent', 'customerDraft' => ['firstName', 'lastName', 'dob', 'gender']]]);
    });

    it('resolves the same identity the frontend simulator would', function (): void {
        officerAt();

        // Both sides run the same 32-bit hash, so a given NIDA number must
        // produce the same person on each — otherwise the wizard would show
        // one identity and the API would store another.
        $expected = app(NidaRegistry::class)->lookup('19900101234567');

        $this->postJson('/api/v1/customers/nida-lookup', ['nidaNumber' => '19900101234567'])
            ->assertJsonPath('data.customerDraft.firstName', $expected->firstName)
            ->assertJsonPath('data.customerDraft.lastName', $expected->lastName)
            ->assertJsonPath('data.customerDraft.dob', $expected->dob);
    });

    it('refuses a lookup for an already-registered NIDA number', function (): void {
        officerAt();
        $this->postJson('/api/v1/customers', registrationPayload())->assertCreated();

        // Caught at step one rather than after a seven-step wizard.
        $this->postJson('/api/v1/customers/nida-lookup', ['nidaNumber' => '19900101234567'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'CUSTOMER_ALREADY_REGISTERED');
    });

    it('verifies a correct OTP and rejects a wrong one', function (): void {
        officerAt();

        $this->postJson('/api/v1/customers/nida-otp-verify', [
            'nidaNumber' => '19900101234567',
            'otp' => NidaRegistry::SIMULATED_OTP,
        ])
            ->assertOk()
            ->assertJsonPath('data.verified', true);

        $this->postJson('/api/v1/customers/nida-otp-verify', [
            'nidaNumber' => '19900101234567',
            'otp' => '000000',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_OTP');
    });

    it('validates the NIDA number length and OTP size', function (): void {
        officerAt();

        $this->postJson('/api/v1/customers/nida-lookup', ['nidaNumber' => '123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nidaNumber']);

        $this->postJson('/api/v1/customers/nida-otp-verify', ['nidaNumber' => '19900101234567', 'otp' => '123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);
    });
});

describe('registration', function (): void {
    beforeEach(function (): void {
        seedCustomerFoundation();
    });

    it('registers a customer with every related record in one transaction', function (): void {
        $officer = officerAt();

        $response = $this->postJson('/api/v1/customers', registrationPayload());

        $response->assertCreated()
            ->assertJsonPath('data.customerNumber', 'CU-000001')
            ->assertJsonPath('data.kycStatus', 'completed')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.createdBy', (string) $officer->id);

        $customer = Customer::query()->sole();

        expect($customer->bankDetails)->not->toBeNull()
            ->and($customer->guarantors)->toHaveCount(1)
            ->and($customer->nextOfKin)->toHaveCount(1)
            ->and($customer->dynamic_form_data['motorcycle_registration_number'])->toBe('MC 123 ABC');
    });

    it('rolls the whole registration back when a nested record fails', function (): void {
        officerAt();

        // A guarantor relationship outside the enum is rejected by validation,
        // so nothing at all should be written.
        $this->postJson('/api/v1/customers', registrationPayload([
            'guarantors' => [[
                'name' => 'Bad Guarantor',
                'phone' => '0755999111',
                'nidaNumber' => null,
                'relationship' => 'landlord',
                'address' => null,
                'occupation' => null,
            ]],
        ]))->assertStatus(422);

        expect(Customer::query()->count())->toBe(0);
    });

    it('marks a customer pending when the category requires extra approval', function (): void {
        officerAt();

        $this->postJson('/api/v1/customers', registrationPayload([
            'customerCategoryId' => CustomerCategory::query()->where('code', 'SME_MEDIUM')->value('id'),
            'dynamicFormData' => [
                'business_type' => 'Wholesale',
                'monthly_turnover' => 4200000,
                'years_in_business' => 6,
            ],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.approvalStatus', 'pending');
    });

    it('queues every registration for manager approval, whatever the category asks', function (): void {
        officerAt();

        $this->postJson('/api/v1/customers', registrationPayload())
            ->assertCreated()
            /* Every registration now queues for a manager, whatever the
               category asks for. `not_required` is a legacy value no new row
               can reach — see the 2026_08_28 migration. */
            ->assertJsonPath('data.approvalStatus', 'pending');
    });

    it('refuses a duplicate NIDA number', function (): void {
        officerAt();
        $this->postJson('/api/v1/customers', registrationPayload())->assertCreated();

        $this->postJson('/api/v1/customers', registrationPayload(['phone' => '0755000999']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nidaNumber'])
            ->assertJsonPath('errors.nidaNumber.0', 'A customer with this NIDA number is already registered.');
    });

    it('registers a customer manually, with no National ID and no verification', function (): void {
        officerAt();

        $payload = registrationPayload();
        unset($payload['nidaNumber'], $payload['faceVerifiedAt']);

        /*
         * A voter's card instead. The requirement is AN identity document, not
         * a NIDA card specifically — a customer holding a voter ID, a driving
         * licence or a passport is identified just as well, and demanding one
         * particular document would exclude people who have valid
         * identification of another kind. See KycEvaluator::identityDocument.
         */
        $payload['voterIdNumber'] = 'VTR-4410992';

        /*
         * §9 made NIDA + OTP + liveness the gate, and this test used to assert
         * a 422 without them. That gate only means something when there is a
         * registry behind it. There is not — what filled those fields was
         * `NidaRegistry`, which invented a name and a date of birth from a hash
         * of the number typed — so requiring them did not prove an identity had
         * been checked, it proved the simulator had run.
         *
         * Manual registration is the supported flow until the integration
         * exists. The record is accepted and rated `incomplete`, which is the
         * true statement about it: a real person, entered by an officer, whose
         * identity nothing has verified.
         */
        $this->postJson('/api/v1/customers', $payload)
            ->assertCreated()
            ->assertJsonPath('data.nidaNumber', null)
            ->assertJsonPath('data.kycStatus', 'incomplete');
    });

    /*
     * This used to assert the reverse: that a National ID could not be supplied
     * without a verification timestamp beside it. That rule was written when a
     * number could only have come from a lookup, and it inverted into a bug —
     * an officer copying a National ID off the card in front of them could not
     * register the customer at all.
     *
     * A number typed by hand is an identity DOCUMENT, captured. What must never
     * happen is the opposite: a client asserting a verification that nothing
     * performed. That is what is checked now.
     */
    it('refuses a claimed NIDA verification while the registry is unavailable', function (): void {
        officerAt();

        $this->postJson('/api/v1/customers', registrationPayload([
            'nidaVerifiedAt' => now()->toIso8601String(),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nidaVerifiedAt']);
    });

    it('refuses a claimed SMS verification while no gateway is configured', function (): void {
        officerAt();

        $this->postJson('/api/v1/customers', registrationPayload([
            'otpVerifiedAt' => now()->toIso8601String(),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['otpVerifiedAt']);
    });

    it('validates dynamic form data against the category schema', function (): void {
        officerAt();

        // daily_income is a required number on the Boda Boda category.
        $this->postJson('/api/v1/customers', registrationPayload([
            'dynamicFormData' => ['motorcycle_registration_number' => 'MC 123 ABC'],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['dynamicFormData.daily_income']);

        $typeError = $this->postJson('/api/v1/customers', registrationPayload([
            'dynamicFormData' => [
                'motorcycle_registration_number' => 'MC 123 ABC',
                'daily_income' => 'not-a-number',
            ],
        ]))->assertStatus(422);

        // Read the bag directly: the key itself contains a dot, which
        // assertJsonPath would interpret as a nesting separator.
        expect($typeError->json('errors')['dynamicFormData.daily_income'][0])
            ->toBe('Average Daily Income (TZS) must be a number.');
    });

    it('drops keys the category schema does not declare', function (): void {
        officerAt();

        $this->postJson('/api/v1/customers', registrationPayload([
            'dynamicFormData' => [
                'motorcycle_registration_number' => 'MC 123 ABC',
                'daily_income' => 35000,
                'smuggled_field' => 'should not persist',
            ],
        ]))->assertCreated();

        // The column is JSON: anything accepted is stored verbatim and would
        // later be indistinguishable from real KYC data.
        expect(Customer::query()->sole()->dynamic_form_data)
            ->not->toHaveKey('smuggled_field');
    });

    it('records the registration in the audit trail', function (): void {
        $officer = officerAt();

        $this->postJson('/api/v1/customers', registrationPayload())->assertCreated();

        $log = AuditLog::query()->where('action', AuditAction::CustomerRegistered->value)->sole();

        expect($log->user_id)->toBe($officer->id)
            ->and($log->after_json['customer_number'])->toBe('CU-000001');
    });

    it('assigns sequential customer numbers', function (): void {
        officerAt();

        $this->postJson('/api/v1/customers', registrationPayload())->assertCreated();
        $second = $this->postJson('/api/v1/customers', registrationPayload([
            'nidaNumber' => '19900109999999',
            'phone' => '0755123999',
        ]))->assertCreated();

        expect($second->json('data.customerNumber'))->toBe('CU-000002');
    });

    it('refuses to register into a branch outside the officer scope', function (): void {
        officerAt('Kakonko');

        // Otherwise branch scoping is trivially bypassed by registering into
        // someone else's branch.
        $this->postJson('/api/v1/customers', registrationPayload([
            'branchId' => Branch::query()->where('name', 'Lindi')->value('id'),
        ]))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'BRANCH_SCOPE_VIOLATION');

        expect(Customer::query()->count())->toBe(0);
    });

    /*
     * This used to assert that missing bank details left KYC incomplete. That
     * is no longer true of every customer and should not be: whether a bank
     * account is required is now the account type's decision, and the default
     * profile does not demand one — many microfinance customers have no bank
     * account at all. The rule is exercised against an account type that does
     * ask for one in CustomerRegistrationRequirementsTest.
     *
     * What is universally true, and what this test now covers, is the shape of
     * the new workflow: a registration is SAVED without a face scan, and the
     * customer is correctly rated incomplete until one passes. That is the
     * whole point of face verification being a separate final step.
     */
    it('saves the registration without a face scan and leaves KYC incomplete', function (): void {
        officerAt();

        $payload = registrationPayload();
        unset($payload['faceVerifiedAt']);

        $this->postJson('/api/v1/customers', $payload)
            ->assertCreated()
            // Derived from the checklist, never asserted by the payload.
            ->assertJsonPath('data.kycStatus', KycStatus::Incomplete->value)
            ->assertJsonPath('data.faceVerifiedAt', null);
    });
});

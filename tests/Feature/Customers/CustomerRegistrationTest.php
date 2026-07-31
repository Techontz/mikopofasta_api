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

    it('marks a customer not_required when the category does not', function (): void {
        officerAt();

        $this->postJson('/api/v1/customers', registrationPayload())
            ->assertCreated()
            ->assertJsonPath('data.approvalStatus', 'not_required');
    });

    it('refuses a duplicate NIDA number', function (): void {
        officerAt();
        $this->postJson('/api/v1/customers', registrationPayload())->assertCreated();

        $this->postJson('/api/v1/customers', registrationPayload(['phone' => '0755000999']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nidaNumber'])
            ->assertJsonPath('errors.nidaNumber.0', 'A customer with this NIDA number is already registered.');
    });

    it('refuses registration without the three verification timestamps', function (): void {
        officerAt();

        $payload = registrationPayload();
        unset($payload['nidaVerifiedAt'], $payload['otpVerifiedAt'], $payload['faceVerifiedAt']);

        // §9 makes NIDA + OTP + liveness the gate. Accepting a payload without
        // them would let a client register an unverified identity by skipping
        // the wizard.
        $this->postJson('/api/v1/customers', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nidaVerifiedAt', 'otpVerifiedAt', 'faceVerifiedAt']);
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

    it('leaves KYC incomplete when bank details are absent', function (): void {
        officerAt();

        $this->postJson('/api/v1/customers', registrationPayload(['bankDetails' => null]))
            ->assertCreated()
            // Derived from the checklist, never asserted by the payload.
            ->assertJsonPath('data.kycStatus', KycStatus::Incomplete->value);
    });
});

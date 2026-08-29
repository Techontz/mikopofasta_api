<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Customers\Services\AccountTypeRequirementResolver;
use App\Models\AccountTypeRequirement;
use App\Models\Customer;
use App\Models\Guarantor;

/**
 * How many guarantors a loan needs is CONFIGURATION.
 *
 * WHAT WAS WRONG. `account_type_requirements.min_guarantors` already existed,
 * was already enforced at registration, and is now editable from
 * Administration → Registration & Eligibility. The loan eligibility engine
 * carried its own hardcoded `MINIMUM_GUARANTORS = 1` alongside it — two
 * answers to one question, agreeing only because both happened to say one.
 * Raising the column would have made registration demand two while the loan
 * gate accepted one, and the loan gate is the one that decides lending.
 *
 * These tests set the column and assert the LOAN GATE moves with it. None of
 * them names a customer, a category or a product code: what is under test is
 * that the configured number is the only number, whatever it is.
 */
beforeEach(function (): void {
    seedLoanFoundation();
});

/**
 * Sets the minimum on every requirement profile, the way an administrator
 * would through the API, and clears the resolver's memo so the next read sees
 * it.
 */
function setMinimumGuarantors(int $minimum): void
{
    AccountTypeRequirement::query()->update(['min_guarantors' => $minimum]);

    app()->forgetInstance(AccountTypeRequirementResolver::class);
}

/** Brings the customer to exactly `$count` guarantors on record. */
function withGuarantors(Customer $customer, int $count): Customer
{
    $customer->guarantors()->delete();

    for ($i = 0; $i < $count; $i++) {
        Guarantor::query()->create([
            'customer_id' => $customer->getKey(),
            'name' => "Guarantor {$i}",
            'phone' => sprintf('07551%05d', 10000 + $i),
            'relationship' => 'friend',
        ]);
    }

    return $customer->refresh();
}

/** The gate's answer for this customer, as the loan screen asks it. */
function eligibilityFor(Customer $customer): array
{
    $response = test()->postJson('/api/v1/loans/check-eligibility', loanPayload($customer))->assertOk();

    return [
        'eligible' => $response->json('data.eligible'),
        'codes' => collect($response->json('data.violations'))->pluck('code')->all(),
        'messages' => collect($response->json('data.violations'))->pluck('message')->all(),
    ];
}

/* -------------------------------------------------------------------------
 | The configured number is the only number
 |------------------------------------------------------------------------- */

describe('the configured minimum', function (): void {
    it('accepts one guarantor when one is configured', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = withGuarantors(eligibleCustomer(), 1);
        setMinimumGuarantors(1);

        expect(eligibilityFor($customer)['eligible'])->toBeTrue();
    });

    it('refuses one guarantor when two are configured', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        /* Registered under the default, then the rule is raised — which is
           the order an institution actually does it in. */
        $customer = withGuarantors(eligibleCustomer(), 1);
        setMinimumGuarantors(2);

        $result = eligibilityFor($customer);

        expect($result['eligible'])->toBeFalse()
            ->and($result['codes'])->toContain('GUARANTORS_REQUIRED')
            /* The message states the CONFIGURED number, so an officer is not
               told "at least 1" while being refused for having one. */
            ->and(implode(' ', $result['messages']))->toContain('At least 2 guarantors are required');
    });

    it('accepts two guarantors when two are configured', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = withGuarantors(eligibleCustomer(), 2);
        setMinimumGuarantors(2);

        expect(eligibilityFor($customer)['eligible'])->toBeTrue();
    });

    it('refuses two guarantors when three are configured', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = withGuarantors(eligibleCustomer(), 2);
        setMinimumGuarantors(3);

        $result = eligibilityFor($customer);

        expect($result['eligible'])->toBeFalse()
            ->and($result['codes'])->toContain('GUARANTORS_REQUIRED')
            ->and(implode(' ', $result['messages']))->toContain('At least 3 guarantors are required');
    });

    it('accepts three guarantors when three are configured', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = withGuarantors(eligibleCustomer(), 3);
        setMinimumGuarantors(3);

        expect(eligibilityFor($customer)['eligible'])->toBeTrue();
    });
});

/* -------------------------------------------------------------------------
 | Changing the configuration changes eligibility
 |------------------------------------------------------------------------- */

describe('changing the configuration', function (): void {
    /*
     * The same customer, the same guarantors, two different answers — because
     * an administrator changed the rule between them. That is the whole point
     * of the column, and the thing the hardcoded constant made impossible.
     */
    it('moves the same customer in and out of eligibility', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $customer = withGuarantors(eligibleCustomer(), 1);

        setMinimumGuarantors(1);
        expect(eligibilityFor($customer)['eligible'])->toBeTrue();

        setMinimumGuarantors(2);
        expect(eligibilityFor($customer)['eligible'])->toBeFalse();

        setMinimumGuarantors(1);
        expect(eligibilityFor($customer)['eligible'])->toBeTrue();
    });

    /* Through the admin endpoint rather than a direct write, so what is proved
       is the path an administrator actually takes. */
    it('follows the administration screen, not just a database write', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = withGuarantors(eligibleCustomer(), 1);

        expect(eligibilityFor($customer)['eligible'])->toBeTrue();

        /*
         * The endpoint edits ONE account type's profile, so the customer must
         * be on one — `eligibleCustomer()` does not set an account type, and
         * without it the resolver falls back to the default row, which has no
         * account type to address.
         */
        $accountType = App\Models\MasterData\AccountType::query()->firstOrFail();
        $customer->forceFill(['account_type_id' => $accountType->getKey()])->save();

        $accountTypeId = $accountType->getKey();
        $profile = app(AccountTypeRequirementResolver::class)->forCustomer($customer->refresh());

        officerAt('Kakonko', RoleName::Admin);

        $this->putJson("/api/v1/registration/requirements/{$accountTypeId}", [
            'requiresEmploymentDetails' => $profile->requires_employment_details,
            'requiresBusinessDetails' => $profile->requires_business_details,
            'requiresBankAccount' => $profile->requires_bank_account,
            'requiresCardDetails' => $profile->requires_card_details,
            'minGuarantors' => 2,
            'minNextOfKin' => $profile->min_next_of_kin,
            'requiresCustomerCategory' => $profile->requires_customer_category,
            'requiresMaritalStatus' => $profile->requires_marital_status,
            'requiresAddress' => $profile->requires_address,
            'requiresIdentityDocument' => $profile->requires_identity_document,
            'requiresFaceVerification' => $profile->requires_face_verification,
            'requiresNidaVerification' => $profile->requires_nida_verification,
            'requiresOtpVerification' => $profile->requires_otp_verification,
        ])->assertOk();

        officerAt('Kakonko', RoleName::LoanOfficer);

        expect(eligibilityFor($customer->refresh())['eligible'])->toBeFalse();
    });
});

/* -------------------------------------------------------------------------
 | Nothing is hardcoded
 |------------------------------------------------------------------------- */

describe('no hardcoded minimum', function (): void {
    /*
     * The proof that the constant is gone. A savings account type may
     * legitimately require no guarantor at all, and the old hardcoded 1 made
     * that unrepresentable — zero could not be configured.
     */
    it('requires none when the configuration says none', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = withGuarantors(eligibleCustomer(), 0);
        setMinimumGuarantors(0);

        expect($customer->guarantors()->count())->toBe(0)
            ->and(eligibilityFor($customer)['eligible'])->toBeTrue();
    });

    it('has no guarantor constant left in the eligibility engine', function (): void {
        $source = file_get_contents(app_path('Domain/Loans/Services/LoanEligibilityChecker.php'));

        expect($source)->not->toContain('MINIMUM_GUARANTORS')
            /* And it reads the configuration instead. */
            ->and($source)->toContain('min_guarantors');
    });

    /* The loan submission endpoint applies the same number as the dry run —
       an officer must never be told they are eligible and then refused. */
    it('applies the same number at submission as at the check', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = withGuarantors(eligibleCustomer(), 1);
        setMinimumGuarantors(2);

        $this->postJson('/api/v1/loans', loanPayload($customer))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'GUARANTORS_REQUIRED');
    });
});

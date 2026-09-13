<?php

declare(strict_types=1);

use App\Domain\Customers\Services\AccountTypeRequirementResolver;
use App\Models\AccountTypeRequirement;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\MasterData\AccountType;

/**
 * How a customer's requirements are composed from up to three profiles.
 *
 * This is the rule the whole configuration architecture rests on: a customer
 * type states what it needs, an account type states what it needs, and the
 * default states the floor. What actually governs the customer is those
 * composed most-specific-first, field by field.
 *
 * The case that matters most — and the one the old single-axis design could
 * not express at all — is a customer type SUPPRESSING something the account
 * type demands: a business customer opening a loan account should not be asked
 * for an employer.
 */
beforeEach(function (): void {
    /* Categories, account types and the default requirement profile. The
       composition rule is about how configured rows combine, so it needs the
       institution's reference data present, not invented here. */
    seedCustomerFoundation();

    $this->resolver = app(AccountTypeRequirementResolver::class);

    $this->default = AccountTypeRequirement::query()->whereNull('account_type_id')
        ->whereNull('customer_category_id')->firstOrFail();
});

it('falls back to the default when nothing more specific exists', function (): void {
    $resolved = $this->resolver->resolve(null, null);

    expect($resolved->requiresAddress)->toBe((bool) $this->default->requires_address)
        ->and($resolved->requiresIdentityDocument)->toBe((bool) $this->default->requires_identity_document)
        ->and($resolved->sources)->toBe(['default']);
});

it('lets an account type add a requirement the default does not make', function (): void {
    $accountType = AccountType::query()->firstOrFail();

    AccountTypeRequirement::query()->updateOrCreate(
        ['account_type_id' => $accountType->getKey(), 'customer_category_id' => null],
        ['requires_employment_details' => true],
    );

    $resolved = $this->resolver->resolve($accountType->getKey(), null);

    expect($resolved->requiresEmploymentDetails)->toBeTrue()
        ->and($resolved->sources)->toBe(['account_type:'.$accountType->getKey(), 'default']);
});

it('lets a customer type SUPPRESS a requirement the account type demands', function (): void {
    /*
     * The case the previous design could not express. The account type asks
     * every customer for employment details; this customer type is for people
     * who run a business and have no employer to name.
     */
    $accountType = AccountType::query()->firstOrFail();
    $category = CustomerCategory::query()->firstOrFail();

    AccountTypeRequirement::query()->updateOrCreate(
        ['account_type_id' => $accountType->getKey(), 'customer_category_id' => null],
        ['requires_employment_details' => true, 'requires_business_details' => false],
    );

    AccountTypeRequirement::query()->updateOrCreate(
        ['account_type_id' => null, 'customer_category_id' => $category->getKey()],
        ['requires_employment_details' => false, 'requires_business_details' => true],
    );

    $resolved = $this->resolver->resolve($accountType->getKey(), $category->getKey());

    expect($resolved->requiresEmploymentDetails)->toBeFalse()
        ->and($resolved->requiresBusinessDetails)->toBeTrue()
        ->and($resolved->sources)->toBe([
            'customer_category:'.$category->getKey(),
            'account_type:'.$accountType->getKey(),
            'default',
        ]);
});

it('inherits per field, so a customer type need not restate the flags it has no opinion on', function (): void {
    $accountType = AccountType::query()->firstOrFail();
    $category = CustomerCategory::query()->firstOrFail();

    AccountTypeRequirement::query()->updateOrCreate(
        ['account_type_id' => $accountType->getKey(), 'customer_category_id' => null],
        ['requires_bank_account' => true, 'min_guarantors' => 2],
    );

    /* Speaks only about business details. Everything else must fall through. */
    AccountTypeRequirement::query()->updateOrCreate(
        ['account_type_id' => null, 'customer_category_id' => $category->getKey()],
        [
            'requires_business_details' => true,
            'requires_bank_account' => null,
            'min_guarantors' => null,
        ],
    );

    $resolved = $this->resolver->resolve($accountType->getKey(), $category->getKey());

    expect($resolved->requiresBusinessDetails)->toBeTrue('the type stated this')
        ->and($resolved->requiresBankAccount)->toBeTrue('inherited from the account type')
        ->and($resolved->minGuarantors)->toBe(2, 'inherited from the account type');
});

it('never relaxes a requirement by omission', function (): void {
    /*
     * A profile of nothing but NULLs must change nothing. Omission defers; it
     * does not decide. This is the invariant that keeps a half-filled admin
     * form from quietly switching KYC off.
     */
    $category = CustomerCategory::query()->firstOrFail();

    AccountTypeRequirement::query()->updateOrCreate(
        ['account_type_id' => null, 'customer_category_id' => $category->getKey()],
        [
            'requires_employment_details' => null,
            'requires_business_details' => null,
            'requires_bank_account' => null,
            'requires_card_details' => null,
            'requires_customer_category' => null,
            'requires_marital_status' => null,
            'requires_address' => null,
            'requires_identity_document' => null,
            'requires_face_verification' => null,
            'requires_nida_verification' => null,
            'requires_otp_verification' => null,
            'requires_category_documents' => null,
            'min_guarantors' => null,
            'min_next_of_kin' => null,
        ],
    );

    $resolved = $this->resolver->resolve(null, $category->getKey());

    expect($resolved->requiresAddress)->toBe((bool) $this->default->requires_address)
        ->and($resolved->requiresIdentityDocument)->toBe((bool) $this->default->requires_identity_document)
        ->and($resolved->requiresFaceVerification)->toBe((bool) $this->default->requires_face_verification)
        ->and($resolved->minGuarantors)->toBe((int) $this->default->min_guarantors);
});

it('resolves a customer from their own type and account type', function (): void {
    $category = CustomerCategory::query()->firstOrFail();

    AccountTypeRequirement::query()->updateOrCreate(
        ['account_type_id' => null, 'customer_category_id' => $category->getKey()],
        ['requires_business_details' => true],
    );

    /* `resolveForCustomer` reads exactly two attributes; an unsaved model
       carrying them exercises the delegation without dragging in the whole
       registration path, which has its own tests. */
    $customer = new Customer([
        'customer_category_id' => $category->getKey(),
        'account_type_id' => null,
    ]);
    $customer->customer_category_id = $category->getKey();
    $customer->account_type_id = null;

    expect($this->resolver->resolveForCustomer($customer)->requiresBusinessDetails)->toBeTrue();
});

it('keeps the account-type admin listing free of customer-type profiles', function (): void {
    /*
     * The two axes are configured on different screens. If the account-type
     * listing returned customer-type rows the admin screen would render one
     * setting twice — the duplicate-control problem this architecture exists
     * to remove.
     */
    $category = CustomerCategory::query()->firstOrFail();

    AccountTypeRequirement::query()->updateOrCreate(
        ['account_type_id' => null, 'customer_category_id' => $category->getKey()],
        ['requires_business_details' => true],
    );

    expect($this->resolver->all()->pluck('customer_category_id')->filter())->toBeEmpty();
});

it('reads a customer type profile back on its own', function (): void {
    $category = CustomerCategory::query()->firstOrFail();

    expect($this->resolver->forCategory($category->getKey()))->toBeNull();

    AccountTypeRequirement::query()->create([
        'account_type_id' => null,
        'customer_category_id' => $category->getKey(),
        'requires_business_details' => true,
    ]);

    expect($this->resolver->forCategory($category->getKey()))->not->toBeNull();
});

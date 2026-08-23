<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Customers\Enums\KycStatus;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * The whole journey, in one test: a customer registered at a counter can
 * actually borrow.
 *
 *     Register (no face scan)  →  Awaiting face verification  →  refused a loan
 *     Face scan, elsewhere     →  KYC complete                →  loan accepted
 *
 * THIS IS WHAT WAS BROKEN. `KycEvaluator` required `nida_verified_at` and
 * `otp_verified_at`, and no integration exists that can produce either. Every
 * customer registered by hand therefore stayed `incomplete` forever, and
 * `LoanEligibilityChecker` refused every application with KYC_INCOMPLETE. The
 * two halves of the system were each correct on their own terms and together
 * they made the product impossible to use.
 *
 * A unit test of the evaluator would not have caught it, because the evaluator
 * was doing exactly what it said. The gap was between two modules, so the test
 * has to span both.
 */
beforeEach(function (): void {
    seedLoanFoundation();
});

it('carries a newly registered customer through to an accepted loan application', function (): void {
    Storage::fake('kyc');

    /* ---------------------------------------------------- 1. registration */
    $officer = officerAt('Kakonko', RoleName::LoanOfficer);

    $payload = registrationPayload();
    /* No face scan at the counter — the case the six-step wizard exists for. */
    unset($payload['faceVerifiedAt']);

    $this->postJson('/api/v1/customers', $payload)->assertCreated();
    $customer = Customer::query()->latest('id')->firstOrFail();

    expect($customer->kyc_status)->toBe(KycStatus::Incomplete)
        ->and($customer->isLoanEligible())->toBeFalse()
        ->and($customer->employee_id)->toBe($officer->getKey());

    /* ------------------------------------- 2. the loan gate refuses, clearly */
    $this->postJson('/api/v1/loans', loanPayload($customer))
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'KYC_INCOMPLETE')
        ->assertJsonPath('errors.eligibility.0', 'Customer KYC is not complete.');

    expect(Loan::query()->count())->toBe(0);

    /* ------------------------- 3. the face scan, by somebody else, elsewhere */
    $verifier = User::factory()->role(RoleName::BranchManager)->create([
        'branch_id' => $customer->branch_id,
    ]);
    Sanctum::actingAs($verifier, ['*']);

    $this->post("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())->assertOk();

    $customer->refresh();

    expect($customer->kyc_status)->toBe(KycStatus::Completed)
        ->and($customer->isLoanEligible())->toBeTrue();

    $this->getJson("/api/v1/customers/{$customer->id}/kyc-status")
        ->assertJsonPath('data.progress.stage', 'loan_eligible')
        ->assertJsonPath('data.progress.nextAction', 'Start a loan application.');

    /* ------------------------------------------- 4. and the loan goes through */
    /* Back to the originating officer: §14 keeps origination and approval in
       different hands, and the manager above must not also be the applicant. */
    Sanctum::actingAs($officer, ['*']);

    $this->postJson('/api/v1/loans', loanPayload($customer))->assertCreated();

    expect(Loan::query()->where('customer_id', $customer->getKey())->count())->toBe(1);
});

/*
 * The other half of the same rule. A category demanding extra approval keeps
 * the customer out of the loan module even with a perfect KYC record — and the
 * progress report names that as the reason rather than reporting a bare
 * "not eligible" the branch cannot act on.
 */
it('reports pending approval as the reason a complete customer still cannot borrow', function (): void {
    Storage::fake('kyc');
    officerAt('Kakonko', RoleName::LoanOfficer);

    $category = App\Models\CustomerCategory::query()->where('code', 'BODA')->firstOrFail();
    $category->update(['requires_extra_approval' => true]);

    $customer = registeredCustomer();

    expect($customer->kyc_status)->toBe(KycStatus::Completed);

    $this->getJson("/api/v1/customers/{$customer->id}/kyc-status")
        ->assertJsonPath('data.isComplete', true)
        ->assertJsonPath('data.isLoanEligible', false)
        ->assertJsonPath('data.progress.stage', 'not_eligible')
        ->assertJsonPath(
            'data.progress.nextAction',
            'This category requires extra approval. A supervisor must approve the registration.',
        );
});

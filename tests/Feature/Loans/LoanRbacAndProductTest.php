<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\PermissionName;
use App\Domain\Auth\Enums\RoleName;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CustomerCategory;
use App\Models\Loan;
use App\Models\LoanProduct;
use Database\Seeders\LoanSeeder;

describe('rbac — separation of duties', function (): void {
    beforeEach(function (): void {
        seedLoanFoundation();
    });

    it('denies the loan list to a role without loans.view', function (): void {
        // HR holds hr.* and reports.view only (§14).
        officerAt('Head Office', RoleName::Hr);

        $this->getJson('/api/v1/loans')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');
    });

    it('denies application creation to a role that can only read', function (): void {
        submittedLoan();

        // Prepared while still authenticated as the Loan Officer: a Credit
        // Officer holds no customers.manage either, so registering the
        // fixture after the switch would fail on the wrong permission.
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = eligibleCustomer();
        $payload = loanPayload($customer);

        // Credit Officer: loans.view + loans.credit_review, never loans.create.
        officerAt('Kakonko', RoleName::CreditOfficer);

        $this->getJson('/api/v1/loans')->assertOk();
        $this->postJson('/api/v1/loans', $payload)->assertForbidden();
    });

    it('keeps approval, credit review and disbursement in three different hands', function (): void {
        $loan = submittedLoan();

        // A Loan Officer raises applications but cannot approve one.
        officerAt('Kakonko', RoleName::LoanOfficer);
        $this->postJson("/api/v1/loans/{$loan->id}/approve-manager", ['decision' => 'approve'])->assertForbidden();

        // A Branch Manager approves but does not run credit review...
        officerAt('Kakonko', RoleName::BranchManager);
        $this->postJson("/api/v1/loans/{$loan->id}/approve-manager", ['decision' => 'approve'])->assertOk();
        $this->postJson("/api/v1/loans/{$loan->id}/telco-verify", ['passed' => true])->assertForbidden();

        // ...the Credit Officer does, but cannot then disburse.
        officerAt('Kakonko', RoleName::CreditOfficer);
        $this->postJson("/api/v1/loans/{$loan->id}/telco-verify", ['passed' => true])->assertOk();
        $this->postJson("/api/v1/loans/{$loan->id}/prepare-disbursement", ['channel' => 'vodacom'])->assertForbidden();

        // §14: "All disbursements execute through Finance."
        officerAt('Head Office', RoleName::Finance);
        $this->postJson("/api/v1/loans/{$loan->id}/prepare-disbursement", ['channel' => 'vodacom'])->assertCreated();
    });

    it('requires authentication throughout', function (): void {
        $this->getJson('/api/v1/loans')->assertUnauthorized();
        $this->getJson('/api/v1/loan-products')->assertUnauthorized();
        $this->getJson('/api/v1/interest-formulas')->assertUnauthorized();
    });
});

describe('branch scoping', function (): void {
    beforeEach(function (): void {
        seedLoanFoundation();
        test()->seed(Database\Seeders\UserSeeder::class);
        test()->seed(Database\Seeders\CustomerSeeder::class);
        test()->seed(LoanSeeder::class);
    });

    it('shows a branch-scoped officer only their own branch loans', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $kakonko = Branch::query()->where('name', 'Kakonko')->value('id');
        $response = $this->getJson('/api/v1/loans?per_page=100');

        $branchIds = collect($response->json('data'))->pluck('branchId')->unique();

        expect($branchIds->all())->toBe([(string) $kakonko]);
    });

    it('gives HQ roles the whole book', function (): void {
        officerAt('Head Office', RoleName::Auditor);

        expect($this->getJson('/api/v1/loans?per_page=100')->json('meta.pagination.total'))
            ->toBe(Loan::query()->count());
    });

    it('refuses to show a loan outside the scope and audits it', function (): void {
        $officer = officerAt('Kakonko', RoleName::LoanOfficer);

        $foreign = Loan::query()
            ->where('branch_id', '!=', $officer->branch_id)
            ->firstOrFail();

        $this->getJson("/api/v1/loans/{$foreign->id}")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'BRANCH_SCOPE_VIOLATION');

        expect(AuditLog::query()->where('action', AuditAction::BranchScopeViolation->value)->exists())->toBeTrue();
    });

    it('keeps a credit officer strictly branch-scoped without the cross-branch grant', function (): void {
        $officer = officerAt('Kakonko', RoleName::CreditOfficer);

        $foreign = Loan::query()
            ->where('branch_id', '!=', $officer->branch_id)
            ->where('status', 'pending_credit_review')
            ->first();

        if ($foreign === null) {
            $this->markTestSkipped('No cross-branch loan at credit review in the seeded book.');
        }

        // §13: "Credit Officer is strictly branch-scoped, no exceptions."
        $this->postJson("/api/v1/loans/{$foreign->id}/telco-verify", ['passed' => true])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'BRANCH_SCOPE_VIOLATION');
    });

    it('lets the explicit cross-branch grant lift that restriction', function (): void {
        $officer = officerAt('Kakonko', RoleName::CreditOfficer);

        $foreign = Loan::query()
            ->where('branch_id', '!=', $officer->branch_id)
            ->where('status', 'pending_credit_review')
            ->first();

        if ($foreign === null) {
            $this->markTestSkipped('No cross-branch loan at credit review in the seeded book.');
        }

        // §13/§14 Decision 1: never implied by scope, always an explicit
        // per-user grant.
        $officer->givePermissionTo(PermissionName::LoansReviewCrossBranch->value);

        $this->postJson("/api/v1/loans/{$foreign->id}/telco-verify", ['passed' => true])->assertOk();
    });
});

describe('loan products', function (): void {
    beforeEach(function (): void {
        seedLoanFoundation();
    });

    it('lists products with their formula and allowed cadences', function (): void {
        // A Loan Officer holds no admin permission but needs this to build an
        // application.
        officerAt('Kakonko', RoleName::LoanOfficer);

        $response = $this->getJson('/api/v1/loan-products');

        $response->assertOk()->assertJsonCount(5, 'data');

        $boda = collect($response->json('data'))->firstWhere('code', 'BODA_WC');

        expect($boda['interestFormulaCode'])->toBe('REDUCING')
            ->and($boda['interestRate'])->toBe('8.000')
            ->and($boda['minAmount'])->toBe('100000.00')
            ->and($boda['allowedRepaymentScheduleIds'])->toHaveCount(2);
    });

    it('returns money and rates as exact decimal strings, never JSON numbers', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $boda = collect($this->getJson('/api/v1/loan-products')->json('data'))->firstWhere('code', 'BODA_WC');

        // JSON has only one numeric type — a double. Emitting a number would
        // hand the browser a float and undo the exact arithmetic.
        expect($boda['minAmount'])->toBeString()
            ->and($boda['interestRate'])->toBeString()
            ->and($boda['penaltyRate'])->toBeString();
    });

    it('stores a flat-fee penalty larger than DECIMAL(6,3) would allow', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $salary = collect($this->getJson('/api/v1/loan-products')->json('data'))
            ->firstWhere('code', 'SALARY_ADVANCE');

        // OSC-2: penalty_rate holds a flat TZS amount for this penalty type.
        expect($salary['penaltyType'])->toBe('flat_fee')
            ->and($salary['penaltyRate'])->toBe('10000.000');
    });

    it('gates product writes on admin.org_settings', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $this->postJson('/api/v1/loan-products', productPayload())->assertForbidden();

        officerAt('Head Office', RoleName::Admin);
        $this->postJson('/api/v1/loan-products', productPayload())->assertCreated();
    });

    it('requires at least one allowed repayment schedule', function (): void {
        officerAt('Head Office', RoleName::Admin);

        // A product no cadence can be applied under is a product no loan can
        // ever use.
        $this->postJson('/api/v1/loan-products', productPayload(['repaymentScheduleIds' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['repaymentScheduleIds']);
    });

    it('rejects a max below the min, for both amount and tenure', function (): void {
        officerAt('Head Office', RoleName::Admin);

        $this->postJson('/api/v1/loan-products', productPayload([
            'minAmount' => '500000.00',
            'maxAmount' => '100000.00',
        ]))->assertStatus(422)->assertJsonValidationErrors(['maxAmount']);

        $this->postJson('/api/v1/loan-products', productPayload([
            'minTenureDays' => 180,
            'maxTenureDays' => 30,
        ]))->assertStatus(422)->assertJsonValidationErrors(['maxTenureDays']);
    });

    it('blocks a structural change while active loans exist', function (): void {
        $loan = submittedLoan();
        officerAt('Head Office', RoleName::Admin);

        $product = LoanProduct::query()->with('repaymentSchedules')->where('code', 'BODA_WC')->sole();
        $flat = App\Models\InterestFormula::query()->where('code', 'FLAT')->sole();

        // Changing the formula would leave live schedules disagreeing with
        // what the product now claims.
        $this->putJson("/api/v1/loan-products/{$product->id}", productPayload([
            'code' => $product->code,
            'interestFormulaId' => $flat->id,
            'repaymentScheduleIds' => $product->repaymentSchedules->pluck('id')->all(),
        ]))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'RESOURCE_IN_USE');
    });

    it('allows a non-structural change while active loans exist', function (): void {
        submittedLoan();
        officerAt('Head Office', RoleName::Admin);

        $product = LoanProduct::query()->with('repaymentSchedules')->where('code', 'BODA_WC')->sole();

        // §6: a rate change takes effect for NEW applications immediately;
        // existing loans are protected by their snapshots.
        $this->putJson("/api/v1/loan-products/{$product->id}", productPayload([
            'code' => $product->code,
            'interestFormulaId' => $product->interest_formula_id,
            'interestRate' => '9.500',
            'repaymentScheduleIds' => $product->repaymentSchedules->pluck('id')->all(),
        ]))->assertOk();

        expect($product->refresh()->interest_rate)->toBe('9.500');
    });

    it('refuses to delete a product with open loans', function (): void {
        submittedLoan();
        officerAt('Head Office', RoleName::Admin);

        $product = LoanProduct::query()->where('code', 'BODA_WC')->sole();

        $this->deleteJson("/api/v1/loan-products/{$product->id}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'RESOURCE_IN_USE');
    });

    it('records product changes in the audit trail', function (): void {
        officerAt('Head Office', RoleName::Admin);

        $this->postJson('/api/v1/loan-products', productPayload())->assertCreated();

        expect(AuditLog::query()->where('action', AuditAction::LoanProductCreated->value)->exists())->toBeTrue();
    });
});

describe('configuration lookups', function (): void {
    beforeEach(function (): void {
        seedLoanFoundation();
    });

    it('serves interest formulas and repayment schedules', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        expect($this->getJson('/api/v1/interest-formulas')->assertOk()->json('data'))->toHaveCount(3)
            ->and($this->getJson('/api/v1/repayment-schedules')->assertOk()->json('data'))->toHaveCount(4);
    });

    it('exposes and replaces the category eligibility rules', function (): void {
        officerAt('Head Office', RoleName::Admin);

        $boda = CustomerCategory::query()->where('code', 'BODA')->sole();

        $current = $this->getJson("/api/v1/customer-categories/{$boda->id}/eligibility")->assertOk();
        expect($current->json('data.rules'))->toHaveCount(2);

        $growth = LoanProduct::query()->where('code', 'SME_GROWTH')->sole();

        $this->putJson("/api/v1/customer-categories/{$boda->id}/eligibility", [
            'rules' => [
                ['loanProductId' => $growth->id, 'maxAmountOverride' => '750000.00', 'requiresExtraApproval' => true],
            ],
        ])->assertOk();

        $updated = $this->getJson("/api/v1/customer-categories/{$boda->id}/eligibility")->json('data.rules');

        expect($updated)->toHaveCount(1)
            ->and($updated[0]['loanProductId'])->toBe((string) $growth->id)
            ->and($updated[0]['maxAmountOverride'])->toBe('750000.00');
    });

    it('gates eligibility edits on admin.org_settings', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $boda = CustomerCategory::query()->where('code', 'BODA')->sole();

        $this->putJson("/api/v1/customer-categories/{$boda->id}/eligibility", ['rules' => []])
            ->assertForbidden();
    });
});

describe('topup eligibility', function (): void {
    beforeEach(function (): void {
        seedLoanFoundation();
    });

    it('reports a non-active loan as ineligible with reasons', function (): void {
        $loan = submittedLoan();
        officerAt('Kakonko', RoleName::LoanOfficer);

        $response = $this->getJson("/api/v1/loans/{$loan->id}/topup-eligibility");

        $response->assertOk()->assertJsonPath('data.eligible', false);

        expect($response->json('data.reasons'))
            ->toContain('Only an active loan can be topped up.')
            ->and($response->json('data.paidPercent'))->toBe('0.000');
    });
});

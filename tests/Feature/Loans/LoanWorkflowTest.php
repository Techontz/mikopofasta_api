<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Loans\Actions\PrepareDisbursementAction;
use App\Domain\Loans\Enums\EMandateStatus;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Services\MandateGateway;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\DisbursementBatch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Support\Money;

describe('application', function (): void {
    beforeEach(function (): void {
        seedLoanFoundation();
    });

    it('submits an application and lands it in pending_manager_approval', function (): void {
        $officer = officerAt('Kakonko');
        $customer = eligibleCustomer();

        $response = $this->postJson('/api/v1/loans', loanPayload($customer));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending_manager_approval')
            ->assertJsonPath('data.customerId', (string) $customer->id)
            ->assertJsonPath('data.officerId', (string) $officer->id);

        expect($response->json('data.loanNumber'))->toStartWith('LN-'.now()->year.'-');
    });

    it('snapshots the product terms so a later product edit cannot rewrite them', function (): void {
        officerAt('Kakonko');
        $customer = eligibleCustomer();
        $product = bodaProduct();

        $this->postJson('/api/v1/loans', loanPayload($customer))->assertCreated();
        $loan = Loan::query()->sole();

        expect($loan->interest_rate_snapshot)->toBe($product->interest_rate);

        // The whole point of §6's snapshot columns.
        $product->update(['interest_rate' => '25.000']);

        expect($loan->refresh()->interest_rate_snapshot)->toBe('8.000')
            ->and($loan->interest_rate_snapshot)->not->toBe('25.000');
    });

    it('records the draft to pending transition in the status history', function (): void {
        officerAt('Kakonko');
        $customer = eligibleCustomer();

        $this->postJson('/api/v1/loans', loanPayload($customer))->assertCreated();
        $loan = Loan::query()->sole();

        $history = $loan->statusHistory()->orderBy('id')->get();

        // §10: "kila action recorded".
        expect($history)->toHaveCount(2)
            ->and($history[0]->from_status)->toBeNull()
            ->and($history[0]->to_status)->toBe(LoanStatus::Draft)
            ->and($history[1]->to_status)->toBe(LoanStatus::PendingManagerApproval);
    });

    it('writes an audit row', function (): void {
        $officer = officerAt('Kakonko');
        $customer = eligibleCustomer();

        $this->postJson('/api/v1/loans', loanPayload($customer))->assertCreated();

        $log = AuditLog::query()->where('action', AuditAction::LoanApplied->value)->sole();
        expect($log->user_id)->toBe($officer->id);
    });

    it('assigns the loan to the customer branch, not the officer branch', function (): void {
        // A Branch Manager at Head Office holds branches.view_all, so can see
        // and act on a Kakonko customer — but the loan belongs to Kakonko.
        officerAt('Head Office', RoleName::Admin);
        $customer = eligibleCustomer();

        $this->postJson('/api/v1/loans', loanPayload($customer))->assertCreated();

        expect(Loan::query()->sole()->branch_id)->toBe($customer->branch_id);
    });

    it('rejects a float-shaped principal with more than two decimals', function (): void {
        officerAt('Kakonko');
        $customer = eligibleCustomer();

        $this->postJson('/api/v1/loans', loanPayload($customer, ['principalAmount' => '500000.005']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['principalAmount']);
    });
});

describe('eligibility gates', function (): void {
    beforeEach(function (): void {
        seedLoanFoundation();
    });

    it('refuses an application when KYC is incomplete', function (): void {
        officerAt('Kakonko');
        $customer = eligibleCustomer();
        $customer->update(['kyc_status' => 'incomplete']);

        $this->postJson('/api/v1/loans', loanPayload($customer))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'KYC_INCOMPLETE');

        expect(Loan::query()->count())->toBe(0);
    });

    it('refuses an application from a frozen customer', function (): void {
        officerAt('Kakonko');
        $customer = eligibleCustomer();
        $customer->update(['status' => 'frozen']);

        $this->postJson('/api/v1/loans', loanPayload($customer))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'CUSTOMER_FROZEN');
    });

    it('refuses when the customer has no guarantor on record', function (): void {
        officerAt('Kakonko');
        $customer = eligibleCustomer();
        $customer->guarantors()->delete();

        $this->postJson('/api/v1/loans', loanPayload($customer))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'GUARANTORS_REQUIRED');
    });

    it('refuses when the category is not eligible for the product', function (): void {
        officerAt('Kakonko');
        $customer = eligibleCustomer();

        // Public Servant Loan is not open to the Boda Boda category.
        $product = LoanProduct::query()->where('code', 'PUBLIC_SERVANT_LOAN')->sole();

        $this->postJson('/api/v1/loans', loanPayload($customer, [
            'loanProductId' => $product->id,
            'repaymentScheduleId' => $product->repaymentSchedules->first()->id,
            'principalAmount' => '600000.00',
            'tenureDays' => 90,
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'CATEGORY_NOT_ELIGIBLE_FOR_PRODUCT');
    });

    it('refuses a repayment schedule the product does not support', function (): void {
        officerAt('Kakonko');
        $customer = eligibleCustomer();

        // Boda Boda Working Capital allows DAILY and WEEKLY, not MONTHLY.
        $monthly = App\Models\RepaymentSchedule::query()->where('code', 'MONTHLY')->sole();

        $this->postJson('/api/v1/loans', loanPayload($customer, ['repaymentScheduleId' => $monthly->id]))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'SCHEDULE_NOT_SUPPORTED_BY_PRODUCT');
    });

    it('enforces the category max_amount_override over the product maximum', function (): void {
        officerAt('Kakonko');
        $customer = eligibleCustomer();

        // Product max is 1,000,000 but Boda Boda is capped at 600,000.
        $this->postJson('/api/v1/loans', loanPayload($customer, ['principalAmount' => '900000.00']))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'LOAN_NOT_ELIGIBLE');

        $this->postJson('/api/v1/loans', loanPayload($customer, ['principalAmount' => '600000.00']))
            ->assertCreated();
    });

    it('refuses an amount below the product minimum and a tenure out of range', function (): void {
        officerAt('Kakonko');
        $customer = eligibleCustomer();

        $this->postJson('/api/v1/loans', loanPayload($customer, ['principalAmount' => '50000.00']))
            ->assertStatus(422);

        $this->postJson('/api/v1/loans', loanPayload($customer, ['tenureDays' => 5]))
            ->assertStatus(422);
    });

    it('refuses a second loan while one is still open', function (): void {
        officerAt('Kakonko');
        $customer = eligibleCustomer();

        $this->postJson('/api/v1/loans', loanPayload($customer))->assertCreated();

        $this->postJson('/api/v1/loans', loanPayload($customer))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'LOAN_NOT_ELIGIBLE');

        expect(Loan::query()->count())->toBe(1);
    });

    it('reports every violation at once, not just the first', function (): void {
        officerAt('Kakonko');
        $customer = eligibleCustomer();
        $customer->update(['kyc_status' => 'incomplete', 'status' => 'frozen']);
        $customer->guarantors()->delete();

        $response = $this->postJson('/api/v1/loans', loanPayload($customer))->assertStatus(422);

        // An officer should see everything wrong at once rather than fixing
        // problems one refusal at a time.
        expect($response->json('errors.eligibility'))->toHaveCount(3);
    });

    it('exposes the same gate as a dry run before submitting', function (): void {
        officerAt('Kakonko');
        $customer = eligibleCustomer();

        $this->postJson('/api/v1/loans/check-eligibility', loanPayload($customer))
            ->assertOk()
            ->assertJsonPath('data.eligible', true);

        $customer->update(['status' => 'frozen']);

        $blocked = $this->postJson('/api/v1/loans/check-eligibility', loanPayload($customer))->assertOk();

        expect($blocked->json('data.eligible'))->toBeFalse()
            ->and(collect($blocked->json('data.violations'))->pluck('code'))->toContain('CUSTOMER_FROZEN');

        // A dry run must not create anything.
        expect(Loan::query()->count())->toBe(0);
    });

    it('blocks a new application during the post-closure cooldown', function (): void {
        officerAt('Kakonko');
        $customer = eligibleCustomer();

        $this->postJson('/api/v1/loans', loanPayload($customer))->assertCreated();
        $loan = Loan::query()->sole();

        // Close it with a cooldown, the way CloseLoanAction does.
        $loan->update([
            'status' => LoanStatus::Closed,
            'frozen_until' => now()->addDays(30)->toDateString(),
        ]);

        $this->postJson('/api/v1/loans', loanPayload($customer))
            ->assertStatus(422)
            ->assertJsonPath('errors.eligibility.0', fn (string $m): bool => str_contains($m, 'cooldown'));
    });
});

describe('manager approval', function (): void {
    beforeEach(function (): void {
        seedLoanFoundation();
    });

    it('approves, generates the schedule, and moves to credit review', function (): void {
        $loan = submittedLoan();
        $manager = officerAt('Kakonko', RoleName::BranchManager);

        $response = $this->postJson("/api/v1/loans/{$loan->id}/approve-manager", ['decision' => 'approve']);

        $response->assertOk()->assertJsonPath('data.status', 'pending_credit_review');

        $loan->refresh();

        expect($loan->approved_by)->toBe($manager->id)
            ->and($loan->approved_at)->not->toBeNull()
            ->and($loan->expected_completion_date)->not->toBeNull()
            ->and($loan->schedules()->count())->toBeGreaterThan(0);
    });

    it('generates a schedule whose principal sums exactly to the loan principal', function (): void {
        $loan = submittedLoan();
        officerAt('Kakonko', RoleName::BranchManager);

        $this->postJson("/api/v1/loans/{$loan->id}/approve-manager", ['decision' => 'approve'])->assertOk();

        $sum = Money::sum($loan->refresh()->schedules->map(fn (LoanSchedule $s) => $s->principalDue()));

        expect($sum->toDecimalString())->toBe($loan->principal_amount);
    });

    it('refuses to let the submitting officer approve their own application', function (): void {
        $officer = officerAt('Kakonko', RoleName::BranchManager);
        $customer = eligibleCustomer();

        // Same person raises it and then tries to approve it.
        $this->postJson('/api/v1/loans', loanPayload($customer))->assertCreated();
        $loan = Loan::query()->sole();

        // §14: an authorization check, not UI hiding.
        $this->postJson("/api/v1/loans/{$loan->id}/approve-manager", ['decision' => 'approve'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'INVALID_LOAN_STATE');

        expect($loan->refresh()->status)->toBe(LoanStatus::PendingManagerApproval)
            ->and($loan->schedules()->count())->toBe(0);
    });

    it('rejects with a mandatory reason and generates no schedule', function (): void {
        $loan = submittedLoan();
        officerAt('Kakonko', RoleName::BranchManager);

        $this->postJson("/api/v1/loans/{$loan->id}/approve-manager", ['decision' => 'reject'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->postJson("/api/v1/loans/{$loan->id}/approve-manager", [
            'decision' => 'reject',
            'reason' => 'Insufficient business history',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejectedReason', 'Insufficient business history');

        expect($loan->refresh()->schedules()->count())->toBe(0);
    });

    it('refuses to decide a loan that is not awaiting approval', function (): void {
        $loan = submittedLoan();
        officerAt('Kakonko', RoleName::BranchManager);

        $this->postJson("/api/v1/loans/{$loan->id}/approve-manager", ['decision' => 'approve'])->assertOk();

        $this->postJson("/api/v1/loans/{$loan->id}/approve-manager", ['decision' => 'approve'])
            ->assertStatus(409);
    });

    it('routes a mandate product through the OTP branch instead', function (): void {
        $loan = submittedLoan(product: 'SALARY_ADVANCE', category: 'PUBLIC_SERVANT');
        officerAt('Kakonko', RoleName::BranchManager);

        $this->postJson("/api/v1/loans/{$loan->id}/approve-manager", ['decision' => 'approve'])
            ->assertOk()
            // §10's conditional branch, decided by the SNAPSHOT.
            ->assertJsonPath('data.status', 'mandate_pending_otp');

        expect($loan->refresh()->mandates()->count())->toBe(1);
    });
});

describe('e-mandate', function (): void {
    beforeEach(function (): void {
        seedLoanFoundation();
    });

    it('verifies a correct OTP and advances to credit review', function (): void {
        $loan = approvedMandateLoan();

        $this->postJson("/api/v1/loans/{$loan->id}/mandate/verify-otp", [
            'otp' => MandateGateway::SIMULATED_OTP,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_credit_review');

        expect($loan->refresh()->mandates()->latest('id')->first()->status)->toBe(EMandateStatus::Active);
    });

    it('marks the mandate failed on a wrong OTP and allows a retry', function (): void {
        $loan = approvedMandateLoan();

        $this->postJson("/api/v1/loans/{$loan->id}/mandate/verify-otp", ['otp' => '000000'])
            ->assertOk()
            ->assertJsonPath('data.status', 'mandate_failed');

        // A retry inserts a NEW mandate; the failed one survives as history.
        $this->postJson("/api/v1/loans/{$loan->id}/mandate/retry")
            ->assertOk()
            ->assertJsonPath('data.status', 'mandate_pending_otp');

        expect($loan->refresh()->mandates()->count())->toBe(2);
    });
});

describe('credit review and disbursement preparation', function (): void {
    beforeEach(function (): void {
        seedLoanFoundation();
    });

    it('passes telco verification and moves to finance', function (): void {
        $loan = loanAtCreditReview();
        officerAt('Kakonko', RoleName::CreditOfficer);

        $this->postJson("/api/v1/loans/{$loan->id}/telco-verify", ['passed' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_finance');

        expect($loan->refresh()->telcoVerifications()->count())->toBe(1);
    });

    it('rejects the loan when telco verification fails', function (): void {
        $loan = loanAtCreditReview();
        officerAt('Kakonko', RoleName::CreditOfficer);

        $this->postJson("/api/v1/loans/{$loan->id}/telco-verify", ['passed' => false])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    });

    it('prepares a disbursement batch without activating the loan', function (): void {
        $loan = loanAtFinance();
        $finance = officerAt('Head Office', RoleName::Finance);

        $response = $this->postJson("/api/v1/loans/{$loan->id}/prepare-disbursement", ['channel' => 'vodacom']);

        $response->assertCreated()
            ->assertJsonPath('data.attemptNumber', 1)
            ->assertJsonPath('data.status', 'pending');

        // §6: the system never assumes success from its own outbound call —
        // the loan waits for the provider callback.
        expect($loan->refresh()->status)->toBe(LoanStatus::AwaitingDisbursement)
            ->and($loan->disbursement_date)->toBeNull();
    });

    it('creates a new batch on retry rather than mutating the failed one', function (): void {
        $loan = loanAtFinance();
        officerAt('Head Office', RoleName::Finance);

        $this->postJson("/api/v1/loans/{$loan->id}/prepare-disbursement", ['channel' => 'vodacom'])->assertCreated();

        // Stand in for the provider callback reporting a failure.
        $loan->refresh()->update(['status' => LoanStatus::DisbursementFailed]);
        $loan->disbursementBatches()->latest('id')->first()->update(['status' => 'failed']);

        $this->postJson("/api/v1/loans/{$loan->id}/retry-disbursement")
            ->assertCreated()
            ->assertJsonPath('data.attemptNumber', 2);

        expect($loan->refresh()->disbursementBatches()->count())->toBe(2)
            ->and(DisbursementBatch::query()->where('attempt_number', 1)->sole()->status->value)->toBe('failed');
    });

    it('escalates after the third failed attempt', function (): void {
        $loan = loanAtFinance();
        officerAt('Head Office', RoleName::Finance);

        $this->postJson("/api/v1/loans/{$loan->id}/prepare-disbursement", ['channel' => 'vodacom'])->assertCreated();

        for ($attempt = 1; $attempt < PrepareDisbursementAction::MAX_ATTEMPTS; $attempt++) {
            $loan->refresh()->update(['status' => LoanStatus::DisbursementFailed]);
            $this->postJson("/api/v1/loans/{$loan->id}/retry-disbursement")->assertCreated();
        }

        $loan->refresh()->update(['status' => LoanStatus::DisbursementFailed]);

        // §6: after 3 failed attempts the loan is escalated for a manual
        // decision rather than retried forever.
        $this->postJson("/api/v1/loans/{$loan->id}/retry-disbursement")->assertStatus(409);

        expect($loan->refresh()->status)->toBe(LoanStatus::Escalated);
    });
});

describe('state machine', function (): void {
    beforeEach(function (): void {
        seedLoanFoundation();
    });

    it('refuses a transition the §10 table does not permit', function (): void {
        $loan = submittedLoan();
        officerAt('Head Office', RoleName::Finance);

        // pending_manager_approval has no path to disbursement.
        $this->postJson("/api/v1/loans/{$loan->id}/prepare-disbursement", ['channel' => 'vodacom'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'INVALID_LOAN_STATE');
    });

    it('records every transition with its actor and reason', function (): void {
        $loan = loanAtFinance();

        $history = $loan->statusHistory()->orderBy('id')->get();

        expect($history->pluck('to_status')->map(fn ($s) => $s->value)->all())
            ->toBe(['draft', 'pending_manager_approval', 'pending_credit_review', 'pending_finance'])
            ->and($history->every(fn ($h): bool => $h->changed_by !== null))->toBeTrue();
    });
});

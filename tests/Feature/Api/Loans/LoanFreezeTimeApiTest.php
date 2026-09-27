<?php

namespace Tests\Feature\Api\Loans;

use App\Enums\Account;
use App\Enums\Duration;
use App\Enums\LoanStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanCategory;
use App\Models\Penalty;
use App\Services\Ledger;
use App\Services\LoanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UploadsLoanAgreement;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

/**
 * Loan category Freeze Time (Days) = EARLY FULL SETTLEMENT freeze counted from DISBURSEMENT: a loan fully settled before
 * its scheduled completion date blocks re-borrowing until disbursed_at + the category's days. Real schedules (1 monthly
 * session = 30 days) and real repayments through LoanService::deposit() (Principal → Penalty → Interest).
 */
class LoanFreezeTimeApiTest extends TestCase
{
    use RefreshDatabase;
    use UploadsLoanAgreement;
    use UsesSecondApprover;

    private const MESSAGE = 'Customer fully settled the previous loan early. Re-borrowing is frozen until 01 October 2026.';

    private Employee $admin;

    private Customer $customer;

    private LoanCategory $category;

    private CustomerCategory $customerType;

    protected function setUp(): void
    {
        parent::setUp();

        config(['integrations.vodacom.driver' => 'test', 'integrations.bank_mandate.driver' => 'test', 'integrations.vodacom.test_outcome' => 'success']);
        $this->travelTo('2026-09-01 10:00:00');
        $this->admin = $this->signInAdmin();
        // Rule 6 (initiator ≠ approver, stage separation) is covered by SegregationOfDutiesTest; these fixtures drive every
        // loan stage as one admin, so the company grants self-approval explicitly.
        $this->grantSelfApproval($this->admin);
        $this->customerType = CustomerCategory::factory()->create(['company_id' => $this->admin->company_id]);
        $this->customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'phone' => '255754000123', 'customer_category_id' => $this->customerType->id]);
        $this->category = $this->category(['freeze_time_days' => 30, 'topup_percent' => 0]);
        // Lending cash lives in the HQ PRINCIPAL A/C (no branch): the customer applies at the branch, HQ pays.
        app(Ledger::class)->openingBalance($this->admin->company_id, Account::Principal, 5000000, 'FLOAT');
    }

    public function test_early_settlement_freezes_from_disbursement_not_from_settlement(): void
    {
        $loan = $this->toActive();
        $this->assertSame('2026-09-01 10:00:00', $loan->disbursed_at->toDateTimeString());
        $this->assertSame('2026-10-01', $loan->end_date->toDateString(), 'One monthly session: scheduled completion 30 days after disbursement.');
        $this->assertSame('2026-10-01', $loan->schedules()->max('due_date'));

        $loan = $this->settle($loan, '2026-09-08 15:00:00');

        $this->assertSame(LoanStatus::Closed, $loan->status);
        $this->assertTrue($loan->early_settlement);
        $this->assertSame('2026-09-01 10:00:00', $loan->freeze_started_at->toDateTimeString());
        $this->assertSame('2026-10-01 10:00:00', $loan->frozen_until->toDateTimeString());
        $this->assertNotSame('2026-10-08 15:00:00', $loan->frozen_until->toDateTimeString(), 'Never settlement date + days.');
        $this->assertNotSame('2026-10-08', $loan->frozen_until->toDateString());
    }

    public function test_new_loan_is_blocked_before_the_freeze_end_on_application_and_eligibility(): void
    {
        $this->settle($this->toActive(), '2026-09-08 15:00:00');
        $this->travelTo('2026-09-10 09:00:00');

        $this->getJson(route('api.v1.loans.categories', $this->customer))->assertOk()
            ->assertJsonPath('eligibility.eligible', true)
            ->assertJsonPath('eligibility.frozen', true)
            ->assertJsonPath('eligibility.allowed', false)
            ->assertJsonPath('eligibility.reasons.0', self::MESSAGE)
            ->assertJsonPath('eligibility.frozen_until', '2026-10-01T10:00:00+00:00')
            ->assertJsonPath('eligibility.freeze.reborrowing_status', 'Frozen')
            ->assertJsonPath('eligibility.freeze.reason', 'Previous loan was fully settled early.')
            ->assertJsonPath('eligibility.freeze.frozen_until_label', '01 October 2026')
            ->assertJsonPath('eligibility.freeze.freeze_started_at', '2026-09-01T10:00:00+00:00')
            ->assertJsonPath('eligibility.freeze.previous_loan.early_settlement', true)
            ->assertJsonPath('eligibility.freeze.previous_loan.expected_completion_date', '2026-10-01')
            ->assertJsonPath('eligibility.freeze.previous_loan.settled_at', '2026-09-08T15:00:00+00:00');

        $this->getJson("/api/v1/customers/{$this->customer->id}/eligibility")->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.freeze.frozen', true)
            ->assertJsonPath('data.can_apply', false);

        $this->postJson(route('api.v1.loans.store'), $this->form())->assertUnprocessable()->assertJsonPath('errors.customer_id.0', self::MESSAGE);
        $this->assertSame(1, Loan::count());
    }

    public function test_manager_and_credit_approval_recheck_the_freeze(): void
    {
        $this->category->update(['topup_percent' => 50]);

        // Manager approval: a top-up application is pending when the running loan is fully settled early.
        $running = $this->toActive();
        $this->travelTo('2026-09-03 10:00:00');
        app(LoanService::class)->deposit($running, 91000, now()->toImmutable());
        $topup = $this->applyLoan(amount: 200000);
        $this->assertSame($running->id, $topup->topup_of_loan_id);
        $this->settle($running, '2026-09-08 10:00:00');

        $this->postJson(route('api.v1.loans.approve-manager', $topup), ['loan_aprove' => 200000])->assertUnprocessable()->assertJsonPath('errors.loan_aprove.0', self::MESSAGE);
        $this->assertSame(LoanStatus::PendingManagerApproval, $topup->fresh()->status);

        $this->travelTo('2026-10-01 10:00:00');
        $this->postJson(route('api.v1.loans.approve-manager', $topup), ['loan_aprove' => 200000])->assertOk();

        // Credit approval: manager-approved before the early settlement, credit review after it.
        $other = $this->otherCustomer();
        $this->travelTo('2026-09-01 10:00:00');
        $running = $this->toActive($other);
        $this->travelTo('2026-09-03 10:00:00');
        app(LoanService::class)->deposit($running, 91000, now()->toImmutable());
        $topup = $this->applyLoan($other, amount: 200000);
        $this->postJson(route('api.v1.loans.approve-manager', $topup), ['loan_aprove' => 200000])->assertOk();
        $this->uploadAgreement($topup)->assertOk();
        $this->postJson(route('api.v1.loans.kyc-verify', $topup))->assertOk();
        $this->settle($running, '2026-09-08 10:00:00');

        $this->postJson(route('api.v1.loans.approve-credit', $topup))->assertUnprocessable()->assertJsonPath('errors.loan.0', self::MESSAGE);
        $this->assertSame(LoanStatus::PendingCreditReview, $topup->fresh()->status);
    }

    public function test_at_and_after_the_freeze_end_the_freeze_check_passes(): void
    {
        $this->settle($this->toActive(), '2026-09-08 15:00:00');

        $this->travelTo('2026-10-01 09:59:59');
        $this->getJson(route('api.v1.loans.categories', $this->customer))->assertJsonPath('eligibility.frozen', true)->assertJsonPath('eligibility.freeze.remaining_seconds', 1);
        $this->postJson(route('api.v1.loans.store'), $this->form())->assertUnprocessable()->assertJsonPath('errors.customer_id.0', self::MESSAGE);

        $this->travelTo('2026-10-01 10:00:00');
        $this->getJson(route('api.v1.loans.categories', $this->customer))->assertOk()
            ->assertJsonPath('eligibility.allowed', true)
            ->assertJsonPath('eligibility.frozen', false)
            ->assertJsonPath('eligibility.frozen_until', null)
            ->assertJsonPath('eligibility.freeze.status', 'expired')
            ->assertJsonPath('eligibility.freeze.reborrowing_status', 'Available')
            ->assertJsonPath('eligibility.freeze.previous_loan.freeze_status', 'expired');
        $this->getJson("/api/v1/customers/{$this->customer->id}/eligibility")->assertJsonPath('data.can_apply', true);
        $this->postJson(route('api.v1.loans.store'), $this->form())->assertCreated();
    }

    public function test_after_expiry_normal_eligibility_still_decides(): void
    {
        $this->settle($this->toActive(), '2026-09-08 15:00:00');
        $this->customer->update(['kyc_status' => 'pending']);

        $this->travelTo('2026-09-20 10:00:00');
        $response = $this->getJson(route('api.v1.loans.categories', $this->customer))->assertJsonPath('eligibility.eligible', false)->assertJsonPath('eligibility.frozen', true);
        $this->assertContains("Please wait for the customer's KYC to be verified!", $response->json('eligibility.reasons'), 'The freeze does not hide other violations.');

        $this->travelTo('2026-10-02 10:00:00');
        $this->getJson(route('api.v1.loans.categories', $this->customer))
            ->assertJsonPath('eligibility.frozen', false)
            ->assertJsonPath('eligibility.allowed', false)
            ->assertJsonPath('eligibility.reasons.0', "Please wait for the customer's KYC to be verified!");
        $this->postJson(route('api.v1.loans.store'), $this->form())->assertUnprocessable()->assertJsonPath('errors.customer_id.0', "Please wait for the customer's KYC to be verified!");
    }

    public function test_settlement_on_the_scheduled_completion_date_is_not_early(): void
    {
        $loan = $this->settle($this->toActive(), '2026-10-01 08:00:00');

        $this->assertSame(LoanStatus::Closed, $loan->status);
        $this->assertFalse($loan->early_settlement);
        $this->assertSame('2026-10-01', $loan->expected_completion_date->toDateString());
        $this->assertNull($loan->freeze_started_at);
        $this->assertNull($loan->freeze_days);
        $this->assertNull($loan->frozen_until);
        $this->getJson(route('api.v1.loans.categories', $this->customer))
            ->assertJsonPath('eligibility.allowed', true)
            ->assertJsonPath('eligibility.freeze.status', 'none')
            ->assertJsonPath('eligibility.freeze.previous_loan.early_settlement', false);
        $this->postJson(route('api.v1.loans.store'), $this->form())->assertCreated();
    }

    public function test_settlement_after_maturity_is_not_early(): void
    {
        $loan = $this->settle($this->toActive(), '2026-10-05 10:00:00');

        $this->assertFalse($loan->early_settlement);
        $this->assertNull($loan->frozen_until);
        $this->postJson(route('api.v1.loans.store'), $this->form())->assertCreated();
    }

    public function test_partial_repayment_does_not_freeze(): void
    {
        $loan = $this->toActive();
        $this->travelTo('2026-09-08 10:00:00');
        app(LoanService::class)->deposit($loan, 120000, now()->toImmutable());

        $loan->refresh();
        $this->assertSame(LoanStatus::Active, $loan->status);
        $this->assertNull($loan->early_settlement);
        $this->assertNull($loan->freeze_started_at);
        $this->assertNull($loan->frozen_until);
        $this->getJson(route('api.v1.loans.categories', $this->customer))->assertJsonPath('eligibility.frozen', false)->assertJsonPath('eligibility.freeze.status', 'none');
    }

    public function test_principal_paid_with_penalty_or_interest_outstanding_is_not_settled(): void
    {
        $loan = $this->toActive();
        Penalty::create(['company_id' => $loan->company_id, 'branch_id' => $loan->branch_id, 'customer_id' => $loan->customer_id, 'loan_id' => $loan->id, 'amount' => 5000, 'penalty_date' => '2026-09-05']);
        $service = app(LoanService::class);

        $this->travelTo('2026-09-07 10:00:00');
        $service->deposit($loan, 130000, now()->toImmutable());
        $loan->refresh();
        $this->assertSame(['principal' => 0.0, 'penalty' => 0.0, 'interest' => 5000.0, 'insurance' => 0.0, 'total' => 5000.0], $service->outstanding($loan));
        $this->assertSame(LoanStatus::Active, $loan->status, 'Principal and penalty paid, interest outstanding: NOT settled.');
        $this->assertNull($loan->early_settlement);
        $this->assertNull($loan->frozen_until);
        $this->assertFalse($this->getJson(route('api.v1.loans.categories', $this->customer))->json('eligibility.frozen'));

        $loan = $this->settle($loan, '2026-09-08 10:00:00', 5000);
        $this->assertSame('2026-09-08 10:00:00', $loan->closed_at->toDateTimeString());
        $this->assertSame('2026-10-01 10:00:00', $loan->frozen_until->toDateTimeString());
    }

    public function test_zero_freeze_time_does_not_freeze(): void
    {
        $this->category->update(['freeze_time_days' => 0]);
        $loan = $this->settle($this->toActive(), '2026-09-08 15:00:00');

        $this->assertTrue($loan->early_settlement);
        $this->assertSame(0, $loan->freeze_days);
        $this->assertNull($loan->freeze_started_at);
        $this->assertNull($loan->frozen_until);
        $this->getJson(route('api.v1.loans.categories', $this->customer))->assertJsonPath('eligibility.allowed', true)->assertJsonPath('eligibility.freeze.status', 'none');
        $this->postJson(route('api.v1.loans.store'), $this->form())->assertCreated();
    }

    public function test_different_categories_have_different_freeze_times_and_midnight_ends_without_time(): void
    {
        $short = $this->category(['name' => 'MSHAHARA', 'freeze_time_days' => 14, 'topup_percent' => 0]);
        $other = $this->otherCustomer();

        $this->settle($this->toActive(), '2026-09-08 15:00:00');

        $this->travelTo('2026-09-02 00:00:00');
        $loan = $this->settle($this->toActive($other, $short), '2026-09-09 12:00:00');
        $this->assertSame(14, $loan->freeze_days);
        $this->assertSame('2026-09-16 00:00:00', $loan->frozen_until->toDateTimeString());

        $this->travelTo('2026-09-15 23:00:00');
        $this->postJson(route('api.v1.loans.store'), $this->form(['customer_id' => $other->id, 'category_id' => $short->id]))->assertUnprocessable()
            ->assertJsonPath('errors.customer_id.0', 'Customer fully settled the previous loan early. Re-borrowing is frozen until 16 September 2026.');

        $this->travelTo('2026-09-16 00:00:00');
        $this->postJson(route('api.v1.loans.store'), $this->form(['customer_id' => $other->id, 'category_id' => $this->category->id]))->assertCreated();
        $this->postJson(route('api.v1.loans.store'), $this->form(['category_id' => $short->id]))->assertUnprocessable()->assertJsonPath('errors.customer_id.0', self::MESSAGE);
    }

    public function test_manual_api_application_cannot_bypass_the_freeze(): void
    {
        $this->category->update(['topup_percent' => 50]);
        $other = $this->category(['name' => 'MSHAHARA', 'freeze_time_days' => 0, 'topup_percent' => 50]);
        $this->settle($this->toActive(), '2026-09-08 15:00:00');
        $this->travelTo('2026-09-10 10:00:00');

        foreach ([$this->form(), $this->form(['category_id' => $other->id]), $this->form(['category_id' => $other->id, 'how_loan' => 200000, 'session' => 3])] as $payload) {
            $this->postJson(route('api.v1.loans.store'), $payload)->assertUnprocessable()->assertJsonPath('errors.customer_id.0', self::MESSAGE);
        }
        $this->assertSame(1, Loan::count());
    }

    public function test_the_freeze_uses_the_loan_disbursement_moment_not_creation_or_approval(): void
    {
        $this->travelTo('2026-08-20 09:00:00');
        $loan = $this->applyLoan();
        $this->travelTo('2026-08-25 11:00:00');
        $this->postJson(route('api.v1.loans.approve-manager', $loan), ['loan_aprove' => 100000])->assertOk();
        $this->uploadAgreement($loan)->assertOk();
        $this->postJson(route('api.v1.loans.kyc-verify', $loan))->assertOk();
        $this->postJson(route('api.v1.loans.approve-credit', $loan))->assertOk();
        $this->travelTo('2026-08-28 12:00:00');
        $this->postJson(route('api.v1.loans.prepare-disbursement', $loan))->assertOk();
        $this->travelTo('2026-09-01 10:00:00');
        $this->postJson(route('api.v1.loans.disburse', $loan))->assertOk();

        $loan = $this->settle($loan->fresh(), '2026-09-08 15:00:00');

        $this->assertSame('2026-08-20 09:00:00', $loan->created_at->toDateTimeString());
        $this->assertSame('2026-08-25 11:00:00', $loan->approved_at->toDateTimeString());
        $this->assertSame('2026-09-01 10:00:00', $loan->freeze_started_at->toDateTimeString());
        $this->assertSame('2026-10-01 10:00:00', $loan->frozen_until->toDateTimeString());
    }

    public function test_settlement_fields_are_persisted_audited_exposed_and_never_rewritten(): void
    {
        $loan = $this->settle($this->toActive(), '2026-09-08 15:00:00');

        $this->assertDatabaseHas('loans', [
            'id' => $loan->id, 'disbursed_at' => '2026-09-01 10:00:00', 'expected_completion_date' => '2026-10-01', 'closed_at' => '2026-09-08 15:00:00',
            'early_settlement' => true, 'freeze_days' => 30, 'freeze_started_at' => '2026-09-01 10:00:00', 'frozen_until' => '2026-10-01 10:00:00',
        ]);
        $audit = AuditLog::where('auditable_id', $loan->id)->where('action', 'SETTLEMENT_FREEZE_DECISION')->sole();
        $this->assertSame('EARLY_SETTLEMENT_FROZEN', $audit->context['decision']);
        $this->assertSame('2026-10-01T10:00:00+00:00', $audit->context['frozen_until']);
        $this->assertSame('2026-09-08T15:00:00+00:00', $audit->context['settled_at']);

        $this->category->update(['freeze_time_days' => 90]);
        $this->travelTo('2026-09-09 10:00:00');
        app(LoanService::class)->recordSettlement($loan);
        $this->assertSame('2026-10-01 10:00:00', $loan->fresh()->frozen_until->toDateTimeString(), 'The decision is taken once; category edits never rewrite it.');
        $this->assertSame(1, AuditLog::where('auditable_id', $loan->id)->where('action', 'SETTLEMENT_FREEZE_DECISION')->count());

        $this->getJson(route('api.v1.loans.show', $loan))->assertOk()
            ->assertJsonPath('data.loan.disbursed_at_iso', '2026-09-01T10:00:00+00:00')
            ->assertJsonPath('data.loan.expected_completion_date', '2026-10-01')
            ->assertJsonPath('data.loan.settled_at', '2026-09-08T15:00:00+00:00')
            ->assertJsonPath('data.loan.early_settlement', true)
            ->assertJsonPath('data.loan.freeze_days', 30)
            ->assertJsonPath('data.loan.freeze_started_at', '2026-09-01T10:00:00+00:00')
            ->assertJsonPath('data.loan.frozen_until', '2026-10-01T10:00:00+00:00')
            ->assertJsonPath('data.loan.freeze_status', 'frozen')
            ->assertJsonPath('data.customer_freeze.status', 'frozen')
            ->assertJsonPath('data.customer_freeze.previous_loan.freeze_status', 'frozen')
            ->assertJsonPath('data.customer_eligible', true);
    }

    public function test_the_latest_early_settlement_blocks_while_an_older_expired_one_does_not(): void
    {
        $old = $this->settle($this->toActive(), '2026-09-08 15:00:00');

        $this->travelTo('2026-10-02 10:00:00');
        $latest = $this->settle($this->toActive(), '2026-10-05 10:00:00');
        $this->assertSame('2026-11-01 10:00:00', $latest->frozen_until->toDateTimeString());

        $this->travelTo('2026-10-10 10:00:00');
        $this->getJson(route('api.v1.loans.categories', $this->customer))
            ->assertJsonPath('eligibility.frozen', true)
            ->assertJsonPath('eligibility.freeze.loan_id', $latest->id)
            ->assertJsonPath('eligibility.reasons.0', 'Customer fully settled the previous loan early. Re-borrowing is frozen until 01 November 2026.');
        $this->assertSame('expired', $old->fresh()->freezeStatus());

        $this->travelTo('2026-11-01 10:00:00');
        $this->getJson(route('api.v1.loans.categories', $this->customer))->assertJsonPath('eligibility.allowed', true)->assertJsonPath('eligibility.freeze.loan_id', $latest->id);
    }

    public function test_early_settlement_after_the_window_already_passed_is_not_blocked(): void
    {
        $long = $this->category(['name' => 'MIEZI MITATU', 'freeze_time_days' => 30, 'topup_percent' => 0]);
        $loan = $this->toActive(category: $long, sessions: 3);
        $this->assertSame('2026-11-30', $loan->end_date->toDateString());

        $loan = $this->settle($loan, '2026-10-16 10:00:00');

        $this->assertTrue($loan->early_settlement);
        $this->assertSame('2026-10-01 10:00:00', $loan->frozen_until->toDateTimeString());
        $this->assertSame('EARLY_SETTLEMENT_FREEZE_ALREADY_EXPIRED', AuditLog::where('auditable_id', $loan->id)->where('action', 'SETTLEMENT_FREEZE_DECISION')->sole()->context['decision']);
        $this->getJson(route('api.v1.loans.categories', $this->customer))
            ->assertJsonPath('eligibility.frozen', false)
            ->assertJsonPath('eligibility.allowed', true)
            ->assertJsonPath('eligibility.freeze.status', 'expired');
        $this->postJson(route('api.v1.loans.store'), $this->form())->assertCreated();
    }

    public function test_a_loan_closed_by_a_top_up_is_a_refinance_not_an_early_settlement(): void
    {
        $this->category->update(['topup_percent' => 50]);
        $running = $this->toActive();
        $this->travelTo('2026-09-05 10:00:00');
        app(LoanService::class)->deposit($running, 91000, now()->toImmutable());

        $topup = $this->toActive(amount: 200000);
        $running->refresh();
        $this->assertSame($running->id, $topup->topup_of_loan_id);
        $this->assertSame(LoanStatus::Active, $topup->status);
        $this->assertSame(LoanStatus::Closed, $running->status);
        $this->assertFalse($running->early_settlement);
        $this->assertNull($running->frozen_until);
        $this->assertSame('SETTLED_BY_TOPUP', AuditLog::where('auditable_id', $running->id)->where('action', 'SETTLEMENT_FREEZE_DECISION')->sole()->context['decision']);
        $this->assertFalse($this->getJson(route('api.v1.loans.categories', $this->customer))->json('eligibility.frozen'));
    }

    public function test_migration_recomputes_closed_loans_with_the_new_rule(): void
    {
        $early = Loan::factory()->create([
            'customer_id' => $this->customer->id, 'loan_category_id' => $this->category->id, 'status' => LoanStatus::Closed,
            'disbursed_at' => '2026-08-01 09:30:00', 'end_date' => '2026-08-31', 'closed_at' => '2026-08-10 12:00:00',
            'freeze_started_at' => '2026-08-10 12:00:00', 'freeze_days' => 30, 'frozen_until' => '2026-09-09 12:00:00',
        ]);
        $late = Loan::factory()->create([
            'customer_id' => $this->customer->id, 'loan_category_id' => $this->category->id, 'status' => LoanStatus::Closed,
            'disbursed_at' => '2026-07-01 09:30:00', 'end_date' => '2026-07-31', 'closed_at' => '2026-07-31 18:00:00',
            'freeze_started_at' => '2026-07-31 18:00:00', 'freeze_days' => 30, 'frozen_until' => '2026-08-30 18:00:00',
        ]);
        $running = Loan::factory()->create([
            'customer_id' => $this->customer->id, 'loan_category_id' => $this->category->id, 'status' => LoanStatus::Active,
            'disbursed_at' => '2026-08-20 09:30:00', 'end_date' => '2026-09-19', 'freeze_started_at' => '2026-08-25 10:00:00', 'freeze_days' => 30, 'frozen_until' => '2026-09-24 10:00:00',
        ]);
        DB::table('loans')->update(['early_settlement' => null, 'expected_completion_date' => null]);

        (require database_path('migrations/2026_09_14_160000_anchor_loan_freeze_to_disbursement.php'))->up();

        $this->assertDatabaseHas('loans', ['id' => $early->id, 'early_settlement' => true, 'expected_completion_date' => '2026-08-31', 'freeze_started_at' => '2026-08-01 09:30:00', 'freeze_days' => 30, 'frozen_until' => '2026-08-31 09:30:00']);
        $this->assertDatabaseHas('loans', ['id' => $late->id, 'early_settlement' => false, 'expected_completion_date' => '2026-07-31', 'freeze_started_at' => null, 'freeze_days' => null, 'frozen_until' => null]);
        $this->assertDatabaseHas('loans', ['id' => $running->id, 'early_settlement' => null, 'freeze_started_at' => null, 'freeze_days' => null, 'frozen_until' => null]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function category(array $attributes): LoanCategory
    {
        $category = LoanCategory::factory()->forCustomerType($this->customerType)->create($attributes + ['insurance' => 0, 'duration' => Duration::Monthly]);
        $category->branches()->attach($this->admin->branch_id);

        return $category;
    }

    private function otherCustomer(): Customer
    {
        return Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'phone' => '255754000999', 'customer_category_id' => $this->customerType->id]);
    }

    /**
     * Pays the whole outstanding balance (or the given amount) with a real repayment at the given moment.
     */
    private function settle(Loan $loan, string $moment, ?float $amount = null): Loan
    {
        $this->travelTo($moment);
        $service = app(LoanService::class);
        $service->deposit($loan, $amount ?? $service->outstanding($loan)['total'], now()->toImmutable());

        return $loan->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'customer_id' => $this->customer->id, 'category_id' => $this->category->id, 'how_loan' => 100000,
            'session' => 1, 'rate' => 'SIMPLE', 'fee_status' => 'YES', 'reason' => 'BIASHARA',
        ];
    }

    private function applyLoan(?Customer $customer = null, ?LoanCategory $category = null, float $amount = 100000, int $sessions = 1): Loan
    {
        $this->postJson(route('api.v1.loans.store'), $this->form([
            'customer_id' => ($customer ?? $this->customer)->id, 'category_id' => ($category ?? $this->category)->id, 'how_loan' => $amount, 'session' => $sessions,
        ]))->assertCreated();

        return Loan::latest('id')->firstOrFail();
    }

    private function toActive(?Customer $customer = null, ?LoanCategory $category = null, float $amount = 100000, int $sessions = 1): Loan
    {
        $loan = $this->applyLoan($customer, $category, $amount, $sessions);
        $this->postJson(route('api.v1.loans.approve-manager', $loan), ['loan_aprove' => $amount])->assertOk();
        $this->uploadAgreement($loan)->assertOk();
        $this->postJson(route('api.v1.loans.kyc-verify', $loan))->assertOk();
        $this->postJson(route('api.v1.loans.approve-credit', $loan))->assertOk();
        $this->postJson(route('api.v1.loans.prepare-disbursement', $loan))->assertOk();
        $this->postJson(route('api.v1.loans.disburse', $loan))->assertOk();

        return $loan->fresh();
    }
}

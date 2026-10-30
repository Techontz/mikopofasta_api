<?php

namespace Tests\Feature\Api\Loans;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanCategory;
use App\Models\LoanDisbursement;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UploadsLoanAgreement;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

/**
 * Enhancement §3.2 (loan Start Date), §7.1 (Return to Previous Stage) and §7.2 (Ready to Pay Out filtered by stage date).
 */
class LoanStartDateAndStageReturnTest extends TestCase
{
    use RefreshDatabase;
    use UploadsLoanAgreement;
    use UsesSecondApprover;

    private Employee $admin;

    private Customer $customer;

    private LoanCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        config(['integrations.vodacom.driver' => 'test', 'integrations.bank_mandate.driver' => 'test', 'integrations.vodacom.test_outcome' => 'success']);
        $this->admin = $this->signInAdmin();
        $this->grantSelfApproval($this->admin);
        $customerType = CustomerCategory::factory()->create(['company_id' => $this->admin->company_id]);
        $this->customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'phone' => '255754000123', 'customer_category_id' => $customerType->id]);
        $this->category = LoanCategory::factory()->forCustomerType($customerType)->create(['insurance' => 0]);
        $this->category->branches()->attach($this->admin->branch_id);
        app(Ledger::class)->openingBalance($this->admin->company_id, Account::Principal, 1000000, 'FLOAT');
    }

    public function test_a_past_start_date_is_refused_and_today_or_later_is_accepted(): void
    {
        $this->postJson(route('api.v1.loans.store'), $this->form(['start_date' => today()->subDay()->toDateString()]))
            ->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->assertSame(0, Loan::count());

        $this->postJson(route('api.v1.loans.store'), $this->form(['start_date' => today()->toDateString()]))->assertCreated();
        $this->assertSame(today()->toDateString(), Loan::sole()->start_date->toDateString());
    }

    public function test_the_schedule_and_reminders_follow_the_start_date(): void
    {
        $start = today()->addDays(12);
        $loan = $this->toActive(['start_date' => $start->toDateString()]);

        $this->assertSame([$start->toDateString()], $loan->schedules()->pluck('due_date')->map(fn ($date): string => substr((string) $date, 0, 10))->all());
        $this->assertSame($start->toDateString(), $loan->end_date->toDateString());
        $this->getJson(route('api.v1.loans.show', $loan))->assertOk()->assertJsonPath('data.loan.start_date', $start->toDateString());
    }

    public function test_without_a_start_date_the_first_repayment_is_one_period_after_disbursement(): void
    {
        $loan = $this->toActive();

        $this->assertSame($loan->duration->addPeriods(today()->toImmutable(), 1)->toDateString(), substr((string) $loan->schedules()->value('due_date'), 0, 10));
    }

    public function test_a_start_date_passed_before_disbursement_starts_one_period_after_disbursement_and_is_recorded(): void
    {
        $loan = $this->toFinance(['start_date' => today()->addDay()->toDateString()]);
        $this->travel(5)->days();

        $this->postJson(route('api.v1.loans.prepare-disbursement', $loan))->assertOk();
        $this->postJson(route('api.v1.loans.disburse', $loan))->assertOk();

        $loan->refresh();
        $this->assertSame($loan->duration->addPeriods(today()->toImmutable(), 1)->toDateString(), substr((string) $loan->schedules()->value('due_date'), 0, 10));
        $this->assertTrue(AuditLog::where('auditable_id', $loan->id)->where('action', 'DISBURSED')->sole()->context['start_date_passed']);
    }

    public function test_an_awaiting_disbursement_loan_returns_to_pending_finance_with_a_reason(): void
    {
        $loan = $this->toFinance();
        $this->postJson(route('api.v1.loans.prepare-disbursement', $loan))->assertOk();

        $this->postJson(route('api.v1.loans.return-to-previous-stage', $loan), [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson(route('api.v1.loans.return-to-previous-stage', $loan), ['reason' => 'Moved to Awaiting Disbursement by mistake'])->assertOk();

        $this->assertSame(LoanStatus::PendingFinance, $loan->fresh()->status);
        $this->assertSame([LoanDisbursement::CANCELLED], LoanDisbursement::where('loan_id', $loan->id)->pluck('status')->unique()->values()->all());
        $audit = AuditLog::where('auditable_id', $loan->id)->where('action', 'RETURNED_TO_PREVIOUS_STAGE')->sole();
        $this->assertSame('Moved to Awaiting Disbursement by mistake', $audit->context['reason']);
        $this->assertSame($this->admin->id, $audit->employee_id);
        $this->assertTrue(AuditLog::where('auditable_id', $loan->id)->where('action', 'CREDIT_APPROVED')->exists() || AuditLog::where('auditable_id', $loan->id)->count() > 3, 'Approval history is kept.');

        $this->postJson(route('api.v1.loans.return-to-previous-stage', $loan), ['reason' => 'Credit must review again'])->assertOk();
        $this->assertSame(LoanStatus::PendingCreditReview, $loan->fresh()->status);
    }

    public function test_a_completed_disbursement_cannot_be_returned(): void
    {
        $loan = $this->toActive();

        $this->postJson(route('api.v1.loans.return-to-previous-stage', $loan), ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('loan');
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
        $this->assertGreaterThan(0, app(Ledger::class)->balance($loan->company_id, Account::LoanReceivable, $loan->branch_id));
    }

    public function test_only_finance_can_return_a_loan(): void
    {
        $loan = $this->toFinance();
        $officer = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'role_id' => $this->admin->company->roles()->where('key', 'loan_officer')->value('id')]);

        $this->actingAs($officer)->postJson(route('api.v1.loans.return-to-previous-stage', $loan), ['reason' => 'x'])->assertForbidden();
    }

    public function test_ready_to_pay_out_filters_by_the_day_the_loan_reached_its_tab(): void
    {
        $this->travel(-3)->days();
        $loan = $this->applyLoan();
        $this->travelBack();
        $loan = $this->toFinance(loan: $loan);

        $today = today()->toDateString();
        $this->getJson(route('api.v1.loans.index', ['stage' => 'disbursement', 'from' => $today, 'to' => $today]))->assertOk()->assertJsonCount(1, 'data');
        $yesterday = today()->subDay()->toDateString();
        $this->getJson(route('api.v1.loans.index', ['stage' => 'disbursement', 'from' => $yesterday, 'to' => $yesterday]))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('api.v1.loans.index', ['stage' => 'disbursement', 'branch_id' => 'all']))->assertOk()->assertJsonCount(1, 'data');
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function applyLoan(array $overrides = []): Loan
    {
        $this->postJson(route('api.v1.loans.store'), $this->form($overrides))->assertCreated();

        return Loan::latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function toFinance(array $overrides = [], ?Loan $loan = null): Loan
    {
        $loan ??= $this->applyLoan($overrides);
        $this->postJson(route('api.v1.loans.approve-manager', $loan), ['loan_aprove' => 100000])->assertOk();
        $this->uploadAgreement($loan)->assertOk();
        $this->postJson(route('api.v1.loans.kyc-verify', $loan))->assertOk();
        $this->postJson(route('api.v1.loans.approve-credit', $loan))->assertOk();

        return $loan->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function toActive(array $overrides = []): Loan
    {
        $loan = $this->toFinance($overrides);
        $this->postJson(route('api.v1.loans.prepare-disbursement', $loan))->assertOk();
        $this->postJson(route('api.v1.loans.disburse', $loan))->assertOk();

        return $loan->fresh();
    }
}

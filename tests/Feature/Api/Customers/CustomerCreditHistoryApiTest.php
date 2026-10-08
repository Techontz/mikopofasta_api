<?php

namespace Tests\Feature\Api\Customers;

use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\LoanTransaction;
use App\Models\Penalty;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvancePayment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Customers\Concerns\BuildsCustomerModule;
use Tests\TestCase;

/**
 * Customer Profile → Credit History: every loan with its source, instalment performance rebuilt from the repayments,
 * arrears, defaults, penalties, interest and salary advances — read only, and agreeing with the Debt Profile.
 */
class CustomerCreditHistoryApiTest extends TestCase
{
    use BuildsCustomerModule, RefreshDatabase;

    private Employee $admin;

    private Customer $customer;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->today = CarbonImmutable::today();
        $this->customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id]);
    }

    public function test_summary_tables_and_performance_come_from_the_records_without_double_counting(): void
    {
        $completed = $this->loan(LoanStatus::Closed, 100000, 30000, ['closed_at' => $this->today->subDays(30)]);
        $this->instalment($completed, 30, 130000);
        $this->deposit($completed, 30, principal: 100000, interest: 30000);

        $overdue = $this->loan(LoanStatus::Overdue, 100000, 30000, ['days_past_due' => 5]);
        $this->instalment($overdue, 20, 65000);
        $this->instalment($overdue, 5, 65000);
        $this->deposit($overdue, 10, principal: 50000, interest: 15000, penalty: 2000);
        $this->penalty($overdue, 2000, paid: 2000);
        $this->penalty($overdue, 5000);

        $defaulted = $this->loan(LoanStatus::Active, 50000, 15000);
        $this->instalment($defaulted, 40, 65000);
        $this->deposit($defaulted, 45, principal: 15000);
        $defaulted->update(['status' => LoanStatus::Default]);

        $legacy = $this->loan(LoanStatus::Active, 200000, 0, ['is_legacy_opening' => true, 'opening_paid_principal' => 80000, 'withdrawn_at' => '2025-06-01']);
        $this->penalty(null, 3000, legacy: true);

        $advance = SalaryAdvance::create([
            'company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'customer_id' => $this->customer->id,
            'amount' => 20000, 'interest_rate' => 20, 'total_payable' => 24000, 'status' => 'active', 'approved_at' => now(),
        ]);
        SalaryAdvancePayment::create(['salary_advance_id' => $advance->id, 'amount' => 4000, 'paid_on' => $this->today]);

        $response = $this->getJson("/api/v1/customers/{$this->customer->id}/credit-history")->assertOk();
        $debt = $this->getJson("/api/v1/customers/{$this->customer->id}/debt")->assertOk()->json('data');
        $overview = $this->getJson("/api/v1/customers/{$this->customer->id}/overview")->assertOk()->json('data');

        $response->assertJsonPath('data.summary.total_loans', 4)
            ->assertJsonPath('data.summary.completed_loans', 1)
            ->assertJsonPath('data.summary.active_loans', 2)
            ->assertJsonPath('data.summary.defaulted_loans', 1)
            ->assertJsonPath('data.summary.total_borrowed', 450000)
            ->assertJsonPath('data.summary.old_system_borrowed', 200000)
            ->assertJsonPath('data.summary.total_repaid', 292000)
            ->assertJsonPath('data.summary.old_system_repaid', 80000)
            ->assertJsonPath('data.summary.total_penalties_charged', 10000)
            ->assertJsonPath('data.summary.total_interest', 75000)
            ->assertJsonPath('data.summary.interest_collected', 45000)
            ->assertJsonPath('data.summary.credit_status.key', 'defaulted');

        // Total Outstanding is the Debt Profile's total, not a second sum on top of it.
        $this->assertSame(263000.0, (float) $debt['total']);
        $this->assertSame((float) $debt['total'], (float) $response->json('data.summary.total_outstanding'));
        $this->assertSame((float) $debt['loan_total'], (float) $response->json('data.summary.outstanding.loans'));
        $this->assertSame((float) $debt['penalty'], (float) $response->json('data.penalties.totals.outstanding'));
        $this->assertSame((float) $debt['salary_advance'], (float) $response->json('data.salary_advances.totals.outstanding'));
        $this->assertSame((float) $overview['loans']['totalDisbursed'], (float) $response->json('data.summary.total_borrowed'));
        $repayableRemain = collect($response->json('data.loans'))->whereIn('status', LoanStatus::values(...LoanStatus::repayable()))->sum('remain');
        $this->assertSame((float) $overview['loans']['outstanding'], (float) $repayableRemain);

        $rows = collect($response->json('data.loans'))->keyBy('id');
        $this->assertCount(4, $rows);
        $this->assertPicked(['source' => 'legacy', 'principal' => null, 'interest' => null, 'principal_interest' => 200000, 'paid' => 80000, 'remain' => 120000, 'loan_date' => '2025-06-01'], $rows[$legacy->id]);
        $this->assertPicked(['source' => 'current', 'principal' => 100000, 'interest' => 30000, 'principal_interest' => 130000, 'paid' => 130000, 'remain' => 0, 'status_group' => 'completed', 'payment_status' => 'paid'], $rows[$completed->id]);
        $this->assertTrue($rows[$overdue->id]['in_arrears']);
        $this->assertSame('default', $rows[$defaulted->id]['status_group']);

        $response->assertJsonPath('data.repayment', [
            'total_instalments' => 4,
            'paid_instalments' => 2,
            'due_instalments' => 4,
            'on_time' => 1,
            'late' => 1,
            'missed' => 2,
            'arrears_events' => 3,
            'arrears_amount' => 115000,
            'max_days_overdue' => 40,
            'times_defaulted' => 1,
            'on_time_rate' => 25,
            'loans_without_schedule' => 1,
        ]);

        $arrears = collect($response->json('data.arrears'));
        $this->assertSame(['missed', 'paid_late', 'partially_paid'], $arrears->pluck('status')->all());
        $this->assertSame([5, 10, 40], $arrears->pluck('days_overdue')->all());
        $this->assertSame([65000, 0, 50000], $arrears->pluck('arrears')->all());

        $response->assertJsonPath('data.defaults.times_defaulted', 1)
            ->assertJsonPath('data.defaults.rows.0.loan_id', $defaulted->id)
            ->assertJsonPath('data.defaults.rows.0.default_date', $this->today->toDateString())
            ->assertJsonPath('data.defaults.rows.0.outstanding_at_default', 50000)
            ->assertJsonPath('data.defaults.rows.0.resolution', 'Unresolved')
            ->assertJsonPath('data.defaults.rows.0.current_status', 'DEFAULT');

        $response->assertJsonPath('data.penalties.totals', ['charged' => 10000, 'paid' => 2000, 'waived' => 0, 'outstanding' => 8000, 'incidents' => 3]);
        $legacyPenalty = collect($response->json('data.penalties.rows'))->firstWhere('source', 'legacy');
        $this->assertNull($legacyPenalty['loan_id']);
        $this->assertSame('Old system penalty (Penalty List import)', $legacyPenalty['reason']);
        $this->assertNull(collect($response->json('data.penalties.rows'))->firstWhere('source', 'current')['reason']);

        $response->assertJsonPath('data.profit.total', 75000)->assertJsonPath('data.profit.excluded_legacy_loans', 1);
        $this->assertNull(collect($response->json('data.profit.rows'))->firstWhere('loan_id', $legacy->id)['interest']);

        $response->assertJsonPath('data.salary_advances.totals', ['count' => 1, 'amount' => 20000, 'total_payable' => 24000, 'paid' => 4000, 'outstanding' => 20000, 'completed' => 0, 'active' => 1, 'defaulted' => null])
            ->assertJsonPath('data.salary_advances.rows.0.remain', 20000);

        $performance = $response->json('data.performance');
        $this->assertStringStartsWith('Customer has taken 4 loans since registration', $performance[0]);
        $this->assertContains('Customer has defaulted once.', $performance);

        $this->assertSame(LoanStatus::Default, $defaulted->fresh()->status, 'reading the history changes nothing');
    }

    public function test_a_customer_without_loans_has_an_empty_history(): void
    {
        $this->getJson("/api/v1/customers/{$this->customer->id}/credit-history")->assertOk()
            ->assertJsonPath('data.summary.total_loans', 0)
            ->assertJsonPath('data.summary.total_outstanding', 0)
            ->assertJsonPath('data.summary.credit_status.key', 'none')
            ->assertJsonPath('data.repayment.on_time_rate', null)
            ->assertJsonPath('data.repayment.max_days_overdue', null)
            ->assertJsonPath('data.loans', [])
            ->assertJsonPath('data.performance', ['Customer has no borrowing history yet.']);
    }

    public function test_it_is_branch_scoped_and_needs_customer_view(): void
    {
        $other = Branch::factory()->create(['company_id' => $this->admin->company_id]);
        $foreign = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $other->id]);
        $officer = $this->employeeWithRole($this->admin, 'loan_officer');

        $this->actingAs($officer)->getJson("/api/v1/customers/{$foreign->id}/credit-history")->assertNotFound();
        $this->actingAs($officer)->getJson("/api/v1/customers/{$this->customer->id}/credit-history")->assertOk();
        $this->actingAs($this->employeeWithRole($this->admin, 'teller'))->getJson("/api/v1/customers/{$this->customer->id}/credit-history")->assertForbidden();
        $this->actingAs($this->admin)->getJson('/api/v1/customers/'.Customer::factory()->create()->id.'/credit-history')->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $row
     */
    private function assertPicked(array $expected, array $row): void
    {
        $this->assertSame($expected, collect(array_keys($expected))->mapWithKeys(fn (string $key): array => [$key => $row[$key] ?? null])->all());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function loan(LoanStatus $status, float $principal, float $interest, array $attributes = []): Loan
    {
        return Loan::factory()->create([
            'customer_id' => $this->customer->id,
            'amount_applied' => $principal,
            'amount_approved' => $principal,
            'interest_amount' => $interest,
            'total_payable' => $principal + $interest,
            'insurance' => 0,
            'status' => $status,
            'withdrawn_at' => $this->today->subDays(60),
            ...$attributes,
        ]);
    }

    private function instalment(Loan $loan, int $daysAgo, float $amount): void
    {
        LoanSchedule::create(['loan_id' => $loan->id, 'due_date' => $this->today->subDays($daysAgo), 'amount' => $amount]);
    }

    private function deposit(Loan $loan, int $daysAgo, float $principal = 0, float $interest = 0, float $penalty = 0): void
    {
        LoanTransaction::create([
            'company_id' => $loan->company_id, 'branch_id' => $loan->branch_id, 'customer_id' => $loan->customer_id, 'loan_id' => $loan->id,
            'type' => 'deposit', 'description' => 'REPAYMENT', 'amount' => $principal + $interest + $penalty,
            'principal' => $principal, 'interest' => $interest, 'penalty' => $penalty, 'transaction_date' => $this->today->subDays($daysAgo),
        ]);
    }

    private function penalty(?Loan $loan, float $amount, float $paid = 0, bool $legacy = false): void
    {
        Penalty::create([
            'company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'customer_id' => $this->customer->id, 'loan_id' => $loan?->id,
            'amount' => $amount, 'paid_amount' => $paid, 'is_legacy_opening' => $legacy, 'penalty_date' => $this->today->subDays(5),
        ]);
    }
}

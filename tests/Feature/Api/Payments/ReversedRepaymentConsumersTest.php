<?php

namespace Tests\Feature\Api\Payments;

use App\Enums\LoanStatus;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Services\Hrm\PerformanceMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A reversed repayment stays in loan_transactions (flagged reversed_at) but counts in no collection figure: goal progress,
 * goal report, staff performance metrics and the customer statement.
 */
class ReversedRepaymentConsumersTest extends TestCase
{
    use InteractsWithRepayments;
    use RefreshDatabase;

    private Employee $admin;

    private Employee $officer;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->officer = $this->employeeWithRole($this->admin, 'loan_officer');
        $this->loan = Loan::factory()->create([
            'customer_id' => Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'employee_id' => $this->officer->id])->id,
            'employee_id' => $this->officer->id,
            'status' => LoanStatus::Active,
            'amount_approved' => 500000,
            'interest_amount' => 100000,
            'total_payable' => 600000,
            'insurance' => 0,
        ]);
        $this->transaction('withdrawal', 500000);
        $this->transaction('deposit', 120000);
        $this->transaction('deposit', 50000, reversed: true);
    }

    public function test_goal_progress_series_and_report_ignore_the_reversed_repayment(): void
    {
        $goal = $this->postJson('/api/v1/goals', [
            'title' => 'Makusanyo', 'scope_type' => 'employee', 'employee_id' => $this->officer->id, 'metric' => 'collections_amount', 'target' => 240000,
            'period_type' => 'monthly', 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.progress.achieved', 120000)->assertJsonPath('data.progress.percent', 50)->json('data');

        $series = $this->getJson("/api/v1/goals/{$goal['id']}")->assertOk()->json('data.series');
        $this->assertEquals(120000, collect($series)->last()['cumulative']);

        $this->getJson('/api/v1/goals/report?metric=collections_amount')->assertOk()
            ->assertJsonPath('data.branches.0.actual', 120000)
            ->assertJsonPath('data.officers.0.actual', 120000);
    }

    public function test_performance_metrics_ignore_the_reversed_repayment(): void
    {
        $rows = app(PerformanceMetrics::class)->forEmployees(collect([$this->officer]), CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth());

        $this->assertSame(120000.0, $rows[0]['collections_amount']);
    }

    public function test_customer_statement_ignores_the_reversed_repayment(): void
    {
        $response = $this->getJson("/api/v1/payments/statement/{$this->loan->customer_id}")->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('totals.deposit', 120000);
        $this->assertSame(480000, (int) $response->json('data.1.remain_debit'));

        $this->getJson(route('api.v1.reports.statement', ['customer_id' => $this->loan->customer_id, 'loan_id' => $this->loan->id]))->assertOk()
            ->assertJsonCount(2, 'data.rows')
            ->assertJsonPath('data.totals.deposit', 120000)
            ->assertJsonPath('data.rows.1.balance', 120000);
    }

    private function transaction(string $type, float $amount, bool $reversed = false): void
    {
        LoanTransaction::create([
            'company_id' => $this->loan->company_id,
            'branch_id' => $this->loan->branch_id,
            'customer_id' => $this->loan->customer_id,
            'loan_id' => $this->loan->id,
            'type' => $type,
            'description' => strtoupper($type),
            'amount' => $amount,
            'principal' => $type === 'deposit' ? $amount : 0,
            'transaction_date' => now()->toDateString(),
            'reversed_at' => $reversed ? now() : null,
            'reversal_reason' => $reversed ? 'Wrong customer' : null,
        ]);
    }
}

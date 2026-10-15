<?php

namespace Tests\Feature\Api\Reports;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanCategory;
use App\Services\Ledger;
use App\Services\LoanService;
use App\Services\Reports\LoanBalances;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Accounting\AccountingTestHelpers;
use Tests\Feature\Api\Loans\BuildsServiceLoans;
use Tests\TestCase;

/**
 * Branch dashboard (GET /dashboard/branch) of Tellers, Loan Officers and Branch Managers: the loan book by status, petty
 * cash, today's collection and disbursement, the portfolio per customer type and the month's pipeline, all from the records
 * of the employee's branch.
 */
class BranchDashboardApiTest extends TestCase
{
    use AccountingTestHelpers, BuildsServiceLoans, RefreshDatabase;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
    }

    public function test_branch_staff_see_the_loan_book_today_and_the_pipeline_of_their_branch(): void
    {
        $today = CarbonImmutable::today();
        $employed = CustomerCategory::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'WATUMISHI']);
        $active = $this->serviceLoan($this->admin, amount: 100000, legacyInsurance: 0);
        $overdue = $this->serviceLoan($this->admin, amount: 80000, legacyInsurance: 0);
        $default = $this->serviceLoan($this->admin, amount: 60000, legacyInsurance: 0);
        $overdue->forceFill(['status' => LoanStatus::Overdue])->save();
        $default->forceFill(['status' => LoanStatus::Default])->save();
        $active->customer->forceFill(['customer_category_id' => $employed->id])->save();
        app(LoanService::class)->deposit($active->fresh(), 30000, $today, 'CASH', $this->admin);

        $pending = $this->application();
        $this->application()->forceFill(['status' => LoanStatus::Rejected])->save();
        app(Ledger::class)->openingBalance($this->admin->company_id, Account::PettyCash, 25000, branch: $this->admin->branch_id);
        $this->serviceLoan($this->admin, branchId: Branch::factory()->create(['company_id' => $this->admin->company_id])->id, legacyInsurance: 0);

        $owed = fn (Loan $loan): float => (float) LoanBalances::join(Loan::whereKey($loan->id))->first()->out_total;

        foreach (['teller', 'loan_officer', 'branch_manager'] as $role) {
            $data = $this->actingAs($this->employeeWithRole($this->admin, $role))->getJson('/api/v1/dashboard/branch')->assertOk()->json('data');

            $this->assertEquals(['count' => 1, 'amount' => $owed($active)], $data['cards']['active'], "{$role}: the loan on time.");
            $this->assertEquals(['count' => 1, 'amount' => $owed($overdue)], $data['cards']['arrears']);
            $this->assertEquals(['count' => 1, 'amount' => $owed($default)], $data['cards']['default']);
            $this->assertSame(3, $data['cards']['outstanding']['count'], 'The other branch\'s loan is not shown.');
            $this->assertEquals(round($owed($active) + $owed($overdue) + $owed($default), 2), $data['cards']['outstanding']['amount']);

            $this->assertEquals(25000, $data['petty_cash']['amount']);
            $this->assertEquals(['amount' => 30000, 'loans' => 1, 'change' => null], $data['today']['collection']);
            $this->assertEquals(240000, $data['today']['disbursement']['amount']);
            $this->assertSame(3, $data['today']['disbursement']['loans']);

            $types = collect($data['customer_types']['rows'])->keyBy('label');
            $this->assertSame(1, $types['WATUMISHI']['customers']);
            $this->assertSame([1, 0, 0], [$types['WATUMISHI']['active_count'], $types['WATUMISHI']['arrears_count'], $types['WATUMISHI']['default_count']]);
            $this->assertEquals($data['cards']['outstanding']['amount'], $data['customer_types']['total']['outstanding'], 'The table adds up to the Total Outstanding card.');

            $pipeline = collect($data['pipeline'])->keyBy('key');
            $this->assertSame(5, $pipeline['applications']['count'], "The three disbursed loans, {$pending->loan_number} and the rejected one.");
            $this->assertSame(1, $pipeline['manager']['count']);
            $this->assertSame(3, $pipeline['approved']['count']);
            $this->assertSame(1, $pipeline['rejected']['count']);
            $this->assertSame(1, collect($data['approvals'])->firstWhere('key', 'applications')['count']);
        }
    }

    public function test_the_dashboard_needs_dashboard_access_and_a_valid_month(): void
    {
        $teller = $this->employeeWithRole($this->admin, 'teller');
        $this->actingAs($teller)->getJson('/api/v1/dashboard/branch?month=2026-13')->assertUnprocessable()->assertJsonValidationErrors(['month']);

        $other = Branch::factory()->create(['company_id' => $this->admin->company_id]);
        $this->actingAs($teller)->getJson("/api/v1/dashboard/branch?branch_id={$other->id}")->assertForbidden();
    }

    private function application(): Loan
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id]);
        $category = LoanCategory::factory()->create(['company_id' => $this->admin->company_id]);

        return app(LoanService::class)->apply($customer, ['loan_category_id' => $category->id, 'amount_applied' => 50000, 'sessions' => 1, 'formula' => 'SIMPLE', 'fee_deduct' => true, 'reason' => 'BIASHARA']);
    }
}

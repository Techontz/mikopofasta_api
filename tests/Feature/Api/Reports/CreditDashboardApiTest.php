<?php

namespace Tests\Feature\Api\Reports;

use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanCategory;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Accounting\AccountingTestHelpers;
use Tests\Feature\Api\Loans\BuildsServiceLoans;
use Tests\TestCase;

/**
 * Credit Department dashboard (GET /dashboard/credit): applications counted by what happened to them, the approval
 * pipeline per stage and today's collections, all from the loan records.
 */
class CreditDashboardApiTest extends TestCase
{
    use AccountingTestHelpers, BuildsServiceLoans, RefreshDatabase;

    private Employee $admin;

    private Employee $credit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->credit = $this->employeeWithRole($this->admin, 'credit_officer');
    }

    public function test_credit_officer_sees_applications_pipeline_and_todays_collections(): void
    {
        $today = CarbonImmutable::today();
        $active = $this->serviceLoan($this->admin, amount: 100000, legacyInsurance: 0);
        $active->schedules()->update(['due_date' => $today->toDateString()]);
        $expectedToday = round((float) $active->schedules()->sum('amount'), 2);
        app(LoanService::class)->deposit($active->fresh(), 30000, $today, 'CASH', $this->admin);

        $pending = $this->application();
        $rejected = $this->application();
        $rejected->forceFill(['status' => LoanStatus::Rejected])->save();
        $review = $this->application();
        $review->forceFill(['status' => LoanStatus::PendingCreditReview, 'approved_at' => now()])->save();

        $data = $this->actingAs($this->credit)->getJson('/api/v1/dashboard/credit?month='.$today->format('Y-m'))->assertOk()->json('data');

        $this->assertSame(4, $data['cards']['applications']);
        $this->assertSame(2, $data['cards']['approved'], 'The disbursed loan and the one past the branch manager are approved.');
        $this->assertSame(1, $data['cards']['rejected']);
        $this->assertSame(1, $data['cards']['active_loans']);

        $month = collect($data['applications_trend'])->firstWhere('month', $today->format('Y-m'));
        $this->assertSame([4, 2, 1], [$month['applied'], $month['approved'], $month['rejected']]);

        $approvals = collect($data['approvals'])->keyBy('key');
        $this->assertSame(1, $approvals['applications']['count'], "Loan {$pending->loan_number} waits for the branch manager.");
        $this->assertSame(1, $approvals['credit_review']['count']);
        $this->assertNull($approvals['disbursements']['link'], 'A credit officer may not open the disbursement page.');

        $this->assertEquals($expectedToday, $data['today']['expected']);
        $this->assertEquals(30000, $data['today']['collected']);
        $this->assertEquals(round($expectedToday - 30000, 2), $data['today']['unpaid']);
        $this->assertSame(1, $data['today']['unpaid_accounts']);
        $this->assertEquals($expectedToday, $data['payment_mandate']['total']);
        $this->assertEquals(30000, $data['payment_mandate']['collected']);

    }

    public function test_customer_type_performance_counts_applications_approvals_and_default_rate(): void
    {
        $employed = CustomerCategory::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'WATUMISHI']);
        $business = CustomerCategory::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'WAJASIRIAMALI']);
        $this->application(customerType: $employed->id);
        $this->application(customerType: $employed->id)->forceFill(['status' => LoanStatus::PendingCreditReview, 'approved_at' => now()])->save();
        $this->application(customerType: $business->id)->forceFill(['status' => LoanStatus::Rejected])->save();
        foreach ([LoanStatus::Default, LoanStatus::Active, LoanStatus::Closed, LoanStatus::Active] as $status) {
            $customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'customer_category_id' => $business->id]);
            Loan::factory()->create(['customer_id' => $customer->id, 'status' => $status])->forceFill(['created_at' => now()->subYear()])->save();
        }

        $data = $this->actingAs($this->credit)->getJson('/api/v1/dashboard/credit')->assertOk()->json('data');

        $this->assertEquals([
            ['label' => 'WATUMISHI', 'applications' => 2, 'approved' => 1, 'default_percent' => 0.0],
            ['label' => 'WAJASIRIAMALI', 'applications' => 1, 'approved' => 0, 'default_percent' => 25.0],
        ], $data['customer_types'], 'One of the four disbursed WAJASIRIAMALI loans is in default; last year\'s loans are not this month\'s applications.');
    }

    public function test_one_branch_shows_only_that_branchs_applications(): void
    {
        $other = Branch::factory()->create(['company_id' => $this->admin->company_id]);
        $this->application();
        $this->application($other->id);

        $data = $this->actingAs($this->credit)->getJson("/api/v1/dashboard/credit?branch_id={$other->id}")->assertOk()->json('data');

        $this->assertSame(1, $data['cards']['applications']);
    }

    public function test_only_staff_who_review_credit_see_it(): void
    {
        $teller = $this->employeeWithRole($this->admin, 'teller');
        $this->actingAs($teller)->getJson('/api/v1/dashboard/credit')->assertForbidden();

        $this->actingAs($this->credit)->getJson('/api/v1/dashboard/credit?month=2026-13')->assertUnprocessable()->assertJsonValidationErrors(['month']);
    }

    private function application(?int $branchId = null, ?int $customerType = null): Loan
    {
        $branchId ??= $this->admin->branch_id;
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $branchId, 'customer_category_id' => $customerType]);
        $category = LoanCategory::factory()->create(['company_id' => $this->admin->company_id]);

        return app(LoanService::class)->apply($customer, ['loan_category_id' => $category->id, 'amount_applied' => 50000, 'sessions' => 1, 'formula' => 'SIMPLE', 'fee_deduct' => true, 'reason' => 'BIASHARA']);
    }
}

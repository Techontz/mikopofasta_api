<?php

namespace Tests\Feature\Api\Loans;

use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanAssessment;
use App\Models\LoanCategory;
use App\Services\Credit\CreditAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Credit assessment endpoints (§36 / §37 / §63): the snapshot recorded when a loan is applied for, permission and branch
 * scoping, re-assessment, the audit trail and the credit review queue — all advisory, none changing the loan.
 */
class CreditAssessmentApiTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    private Customer $customer;

    private LoanCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        config(['credit.context.cache_seconds' => 0]);
        $this->admin = $this->signInAdmin();
        $customerType = CustomerCategory::factory()->create(['company_id' => $this->admin->company_id]);
        $this->customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'customer_category_id' => $customerType->id, 'monthly_income' => 1000000, 'dependents' => 0]);
        $this->category = LoanCategory::factory()->forCustomerType($customerType)->create(['insurance' => 0]);
        $this->category->branches()->attach($this->admin->branch_id);
    }

    public function test_applying_for_a_loan_records_the_assessment_snapshot_at_application_time(): void
    {
        $this->travelTo(now()->setTime(10, 15));
        $loan = $this->applyLoan();

        $assessment = LoanAssessment::sole();
        $this->assertSame($loan->id, $assessment->loan_id);
        $this->assertSame($this->admin->id, $assessment->assessed_by);
        $this->assertEquals(100000, $assessment->requested_amount);
        $this->assertSame(now()->toDateTimeString(), $assessment->assessed_at->toDateTimeString());
        $this->assertSame((string) config('credit.engine_version'), $assessment->engine_version);
        $this->assertSame(['loan_product'], $assessment->excluded_factors);
        $this->assertCount(10, $assessment->factors);
        $this->assertStringContainsString('The requested amount is 100,000', $assessment->explanation);
        $this->assertSame(LoanStatus::PendingManagerApproval, $loan->fresh()->status);

        $this->getJson(route('api.v1.loans.credit-assessment.show', $loan))->assertOk()
            ->assertJsonPath('data.stored', true)
            ->assertJsonPath('data.id', $assessment->id)
            ->assertJsonPath('data.advisory', true)
            ->assertJsonPath('data.summary.requested_amount', 100000)
            ->assertJsonPath('data.excluded_factors', ['loan_product']);

        $this->getJson(route('api.v1.loans.show', $loan))->assertOk()
            ->assertJsonPath('data.credit_assessment.stored', true)
            ->assertJsonPath('data.credit_assessment.id', $assessment->id);
    }

    public function test_an_assessment_failure_never_blocks_the_application(): void
    {
        $this->mock(CreditAssessment::class, function (MockInterface $mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('credit engine unavailable'));
        });

        $this->postJson(route('api.v1.loans.store'), $this->form())->assertCreated();

        $loan = Loan::sole();
        $this->assertSame(LoanStatus::PendingManagerApproval, $loan->status);
        $this->assertSame(0, LoanAssessment::count());
    }

    public function test_the_loan_screen_survives_an_assessment_failure(): void
    {
        $loan = Loan::factory()->create(['customer_id' => $this->customer->id, 'loan_category_id' => $this->category->id]);
        $this->mock(CreditAssessment::class, function (MockInterface $mock): void {
            $mock->shouldReceive('forDisplay')->andReturn(null);
        });

        $this->getJson(route('api.v1.loans.show', $loan))->assertOk()->assertJsonPath('data.credit_assessment', null);
    }

    public function test_reassessing_stores_a_new_snapshot_without_changing_the_loan(): void
    {
        $loan = $this->applyLoan();
        $before = $loan->fresh()->getAttributes();

        $this->postJson(route('api.v1.loans.credit-assessment.store', $loan))->assertCreated()
            ->assertJsonPath('data.stored', true)
            ->assertJsonPath('data.advisory', true);

        $this->assertSame($before, $loan->fresh()->getAttributes());
        $this->assertSame(2, LoanAssessment::where('loan_id', $loan->id)->count());

        $newest = LoanAssessment::where('loan_id', $loan->id)->latestFirst()->first();
        $this->getJson(route('api.v1.loans.credit-assessment.index', $loan))->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('data.0.assessed_by', $this->admin->full_name);

        $this->getJson(route('api.v1.loans.credit-assessment.show', ['loan' => $loan, 'refresh' => 1]))->assertOk()
            ->assertJsonPath('data.stored', false)
            ->assertJsonStructure(['data' => ['recommended_amount', 'score', 'factors' => [['key', 'weight', 'value', 'contribution', 'direction', 'evidence' => ['sources', 'data', 'notes']]], 'explanation', 'contextual_influence']]);
        $this->assertSame(2, LoanAssessment::count(), 'a refreshed preview is never stored');
    }

    public function test_a_loan_without_a_snapshot_shows_a_preview(): void
    {
        $loan = Loan::factory()->create(['customer_id' => $this->customer->id, 'loan_category_id' => $this->category->id, 'restoration' => 130000]);

        $this->getJson(route('api.v1.loans.credit-assessment.show', $loan))->assertOk()
            ->assertJsonPath('data.stored', false)
            ->assertJsonPath('data.loan_id', $loan->id);
        $this->getJson(route('api.v1.loans.show', $loan))->assertOk()->assertJsonPath('data.credit_assessment.stored', false);
        $this->assertSame(0, LoanAssessment::count());
    }

    public function test_endpoints_are_permission_gated_and_branch_scoped(): void
    {
        $loan = $this->applyLoan();
        $otherBranch = Branch::factory()->create(['company_id' => $this->admin->company_id]);

        $this->actingAs($this->employee('branch_manager', $otherBranch->id));
        $this->getJson(route('api.v1.loans.credit-assessment.show', $loan))->assertNotFound();
        $this->postJson(route('api.v1.loans.credit-assessment.store', $loan))->assertNotFound();
        $this->getJson(route('api.v1.loans.credit-assessment.index', $loan))->assertNotFound();
        $this->getJson(route('api.v1.loans.credit-assessment.queue'))->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->employee('teller', $this->admin->branch_id));
        $this->getJson(route('api.v1.loans.credit-assessment.show', $loan))->assertOk();
        $this->postJson(route('api.v1.loans.credit-assessment.store', $loan))->assertForbidden();
        $this->getJson(route('api.v1.loans.credit-assessment.queue'))->assertForbidden();

        $this->actingAs($this->employee('hr', $this->admin->branch_id));
        $this->getJson(route('api.v1.loans.credit-assessment.show', $loan))->assertForbidden();

        $this->actingAs($this->employee('branch_manager', $this->admin->branch_id));
        $this->postJson(route('api.v1.loans.credit-assessment.store', $loan))->assertCreated();

        $this->actingAs($this->employee('credit_officer', $otherBranch->id));
        $this->getJson(route('api.v1.loans.credit-assessment.show', $loan))->assertOk();
        $this->assertSame(2, LoanAssessment::count());
    }

    public function test_queue_lists_applications_awaiting_a_decision_with_their_latest_recommendation(): void
    {
        $loan = $this->applyLoan();
        Loan::factory()->create(['customer_id' => $this->customer->id, 'loan_category_id' => $this->category->id, 'status' => LoanStatus::Closed]);
        $this->postJson(route('api.v1.loans.credit-assessment.store', $loan))->assertCreated();
        $latest = LoanAssessment::where('loan_id', $loan->id)->latestFirst()->first();

        $this->getJson(route('api.v1.loans.credit-assessment.queue'))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.loan_id', $loan->id)
            ->assertJsonPath('data.0.requested_amount', 100000)
            ->assertJsonPath('data.0.assessment.id', $latest->id)
            ->assertJsonPath('data.0.assessment.advisory', true)
            ->assertJsonPath('meta.total', 1);
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

    private function applyLoan(): Loan
    {
        $this->postJson(route('api.v1.loans.store'), $this->form())->assertCreated();

        return Loan::latest('id')->firstOrFail();
    }

    private function employee(string $role, int $branchId): Employee
    {
        return Employee::factory()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $branchId,
            'role_id' => $this->admin->company->roles()->where('key', $role)->value('id'),
        ]);
    }
}

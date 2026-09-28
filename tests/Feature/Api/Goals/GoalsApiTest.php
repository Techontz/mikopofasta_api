<?php

namespace Tests\Feature\Api\Goals;

use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Goal;
use App\Models\Loan;
use App\Models\LoanTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalsApiTest extends TestCase
{
    use RefreshDatabase;

    private function employee(Employee $admin, string $role, ?Branch $branch = null): Employee
    {
        return Employee::factory()->create([
            'company_id' => $admin->company_id,
            'branch_id' => ($branch ?? $admin->branch)->id,
            'role_id' => $admin->company->roles()->where('key', $role)->value('id'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Wateja wapya mwezi huu',
            'scope_type' => 'company',
            'metric' => 'new_customers',
            'target' => 4,
            'period_type' => 'monthly',
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
        ], $overrides);
    }

    private function transaction(Loan $loan, string $type, float $amount): void
    {
        LoanTransaction::create([
            'company_id' => $loan->company_id,
            'branch_id' => $loan->branch_id,
            'customer_id' => $loan->customer_id,
            'loan_id' => $loan->id,
            'type' => $type,
            'description' => strtoupper($type),
            'amount' => $amount,
            'transaction_date' => now()->toDateString(),
        ]);
    }

    public function test_admin_sets_goals_and_progress_is_computed_from_existing_data(): void
    {
        $admin = $this->signInAdmin();
        $officer = $this->employee($admin, 'loan_officer');
        Customer::factory()->count(2)->create(['branch_id' => $admin->branch_id, 'employee_id' => $officer->id]);
        Customer::factory()->create(['branch_id' => $admin->branch_id, 'created_at' => now()->subYear()]);

        $loan = Loan::factory()->create([
            'customer_id' => Customer::factory()->create(['branch_id' => $admin->branch_id, 'employee_id' => $officer->id])->id,
            'employee_id' => $officer->id,
            'status' => LoanStatus::Active,
            'amount_approved' => 500000,
        ]);
        $this->transaction($loan, 'withdrawal', 500000);
        $this->transaction($loan, 'deposit', 120000);

        $this->postJson('/api/v1/goals', $this->payload())
            ->assertCreated()->assertJsonPath('message', 'Goal Registered successfully')
            ->assertJsonPath('data.progress.achieved', 3)
            ->assertJsonPath('data.progress.percent', 75);

        $this->postJson('/api/v1/goals', $this->payload(['title' => 'Utoaji', 'scope_type' => 'branch', 'branch_id' => $admin->branch_id, 'metric' => 'disbursement_amount', 'target' => 1000000]))
            ->assertCreated()->assertJsonPath('data.progress.achieved', 500000)->assertJsonPath('data.progress.remaining', 500000);

        $officerGoal = $this->postJson('/api/v1/goals', $this->payload(['title' => 'Makusanyo', 'scope_type' => 'employee', 'employee_id' => $officer->id, 'metric' => 'collections_amount', 'target' => 100000]))
            ->assertCreated()->assertJsonPath('data.progress.status', 'achieved')->json('data');

        $this->postJson('/api/v1/goals', $this->payload(['title' => 'Mikopo', 'scope_type' => 'employee', 'employee_id' => $officer->id, 'metric' => 'loans_count', 'target' => 2]))
            ->assertCreated()->assertJsonPath('data.progress.achieved', 1);

        $this->assertDatabaseHas('audit_logs', ['action' => 'Goal.created']);

        $this->getJson('/api/v1/goals')->assertOk()->assertJsonCount(4, 'data');
        $show = $this->getJson("/api/v1/goals/{$officerGoal['id']}")->assertOk();
        $this->assertEquals(120000, collect($show->json('data.series'))->last()['cumulative']);

        $this->getJson('/api/v1/goals/report?metric=disbursement_amount')->assertOk()
            ->assertJsonPath('data.branches.0.actual', 500000)
            ->assertJsonPath('data.branches.0.target', 1000000)
            ->assertJsonPath('data.officers.0.employee_id', $officer->id);

        $this->putJson("/api/v1/goals/{$officerGoal['id']}", $this->payload(['title' => 'Makusanyo', 'scope_type' => 'employee', 'employee_id' => $officer->id, 'metric' => 'collections_amount', 'target' => 240000]))
            ->assertOk()->assertJsonPath('data.progress.percent', 50);

        $this->deleteJson("/api/v1/goals/{$officerGoal['id']}")->assertOk()->assertJsonPath('message', 'Goal Deleted successfully');
        $this->assertDatabaseCount('goals', 3);
    }

    public function test_validation(): void
    {
        $this->signInAdmin();

        $this->postJson('/api/v1/goals', ['scope_type' => 'branch', 'start_date' => '2026-09-30', 'end_date' => '2026-09-01', 'target' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'branch_id', 'metric', 'target', 'period_type', 'end_date']);
    }

    public function test_viewers_cannot_manage_and_only_see_goals_in_their_scope(): void
    {
        $admin = $this->signInAdmin();
        $otherBranch = Branch::factory()->create(['company_id' => $admin->company_id]);
        $officer = $this->employee($admin, 'loan_officer');
        $colleague = $this->employee($admin, 'loan_officer');
        $manager = $this->employee($admin, 'branch_manager');

        $base = ['company_id' => $admin->company_id, 'metric' => 'new_customers', 'target' => 10, 'period_type' => 'monthly', 'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth()];
        Goal::create($base + ['title' => 'Company', 'scope_type' => 'company']);
        Goal::create($base + ['title' => 'Own branch', 'scope_type' => 'branch', 'branch_id' => $admin->branch_id]);
        Goal::create($base + ['title' => 'Other branch', 'scope_type' => 'branch', 'branch_id' => $otherBranch->id]);
        Goal::create($base + ['title' => 'Mine', 'scope_type' => 'employee', 'employee_id' => $officer->id]);
        $colleagueGoal = Goal::create($base + ['title' => 'Colleague', 'scope_type' => 'employee', 'employee_id' => $colleague->id]);

        $this->actingAs($officer);
        $this->assertEqualsCanonicalizing(['Company', 'Own branch', 'Mine'], collect($this->getJson('/api/v1/goals')->assertOk()->json('data'))->pluck('title')->all());
        $this->getJson("/api/v1/goals/{$colleagueGoal->id}")->assertNotFound();
        $this->postJson('/api/v1/goals', $this->payload())->assertForbidden();
        $this->deleteJson("/api/v1/goals/{$colleagueGoal->id}")->assertForbidden();

        $this->actingAs($manager);
        $this->assertEqualsCanonicalizing(['Company', 'Own branch', 'Mine', 'Colleague'], collect($this->getJson('/api/v1/goals')->json('data'))->pluck('title')->all());
    }
}

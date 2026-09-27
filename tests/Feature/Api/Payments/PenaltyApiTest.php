<?php

namespace Tests\Feature\Api\Payments;

use App\Enums\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\Penalty;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penalty List / Paid Penalty (live admin/get_penart_list, admin/penart_paid_list) and customer statement data.
 */
class PenaltyApiTest extends TestCase
{
    use InteractsWithRepayments;
    use RefreshDatabase;

    public function test_list_shows_only_open_penalties_and_filters_by_branch(): void
    {
        $admin = $this->signInAdmin();
        $open = $this->activeLoan($admin, penalty: 30266)->penalties()->sole();
        Penalty::whereKey($this->activeLoan($admin, penalty: 5000)->penalties()->sole()->id)->update(['is_waived' => true]);
        Penalty::whereKey($this->activeLoan($admin, penalty: 7000)->penalties()->sole()->id)->update(['paid_amount' => 7000]);
        $otherBranch = Branch::factory()->create(['company_id' => $admin->company_id]);
        $this->activeLoan($admin, penalty: 1000, branch: $otherBranch);

        $this->getJson('/api/v1/penalties')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/penalties?blanch_id={$admin->branch_id}")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id)->assertJsonPath('data.0.amount', 30266)->assertJsonPath('data.0.loan_amount', 130000);

        $manager = $this->employeeWithRole($admin, 'branch_manager');
        $this->actingAs($manager)->getJson('/api/v1/penalties')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($manager)->getJson("/api/v1/penalties?branch_id={$otherBranch->id}")->assertForbidden();
        $this->actingAs($this->employeeWithRole($admin, 'teller'))->getJson('/api/v1/penalties')->assertForbidden();
    }

    public function test_paying_a_penalty_records_payment_and_ledger(): void
    {
        $admin = $this->signInAdmin();
        $penalty = $this->activeLoan($admin, penalty: 10000)->penalties()->sole();

        $this->postJson("/api/v1/penalties/{$penalty->id}/pay", [])->assertUnprocessable()->assertJsonValidationErrors('penart_paid');
        $this->postJson("/api/v1/penalties/{$penalty->id}/pay", ['penart_paid' => 20000])->assertUnprocessable()->assertJsonValidationErrors('penart_paid');
        $this->postJson("/api/v1/penalties/{$penalty->id}/pay", ['penart_paid' => 10000])->assertOk()->assertJsonPath('message', 'Penalty Paid successfully');

        $this->assertEquals(10000, $penalty->fresh()->paid_amount);
        $this->assertSame(10000.0, $this->balance($admin, Account::Penalty, $admin->branch_id));
        $this->getJson('/api/v1/penalties/paid')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', 10000);
        $this->getJson('/api/v1/penalties/paid?from='.today()->addDay()->toDateString())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/penalties')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_penalty_can_be_waived_with_audit_and_other_companies_are_unreachable(): void
    {
        $admin = $this->signInAdmin();
        $penalty = $this->activeLoan($admin, penalty: 10000)->penalties()->sole();

        $this->postJson("/api/v1/penalties/{$penalty->id}/waive")->assertOk()->assertJsonPath('message', 'Penalty Removed successfully');
        $this->assertTrue($penalty->fresh()->is_waived);
        $this->assertTrue(AuditLog::where('action', 'Penalty.waived')->where('auditable_id', $penalty->id)->exists());

        $foreignLoan = Loan::factory()->create();
        $foreign = Penalty::create(['company_id' => $foreignLoan->company_id, 'branch_id' => $foreignLoan->branch_id, 'customer_id' => $foreignLoan->customer_id, 'loan_id' => $foreignLoan->id, 'amount' => 1000, 'penalty_date' => today()]);
        $this->postJson("/api/v1/penalties/{$foreign->id}/waive")->assertNotFound();
    }

    public function test_customer_statement_returns_allocation_split(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin, penalty: 10000);
        app(LoanService::class)->deposit($loan, 105000, CarbonImmutable::today());

        $this->getJson("/api/v1/payments/statement/{$loan->customer_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.principal', 100000)
            ->assertJsonPath('data.0.penalty', 5000)
            ->assertJsonPath('data.0.interest', 0)
            ->assertJsonPath('data.0.remain_debit', 25000)
            ->assertJsonPath('totals.deposit', 105000);

        $this->actingAs($this->employeeWithRole($admin, 'loan_officer'))->getJson("/api/v1/payments/statement/{$loan->customer_id}")->assertForbidden();
    }
}

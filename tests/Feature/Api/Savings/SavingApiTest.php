<?php

namespace Tests\Feature\Api\Savings;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\Saving;
use App\Services\Ledger;
use App\Services\LoanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

class SavingApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    public function test_deposit_and_taken_withdrawal_move_the_saving_ledger(): void
    {
        $admin = $this->signInAdmin();
        $customer = Customer::factory()->create(['branch_id' => $admin->branch_id]);
        $ledger = app(Ledger::class);

        $this->postJson("/api/v1/savings/customers/{$customer->id}/deposits", ['dep_sav' => 50000])->assertCreated()->assertJsonPath('message', 'Saving Deposit successfully');
        $this->postJson("/api/v1/savings/customers/{$customer->id}/withdrawals", ['with_sav' => 60000, 'action' => 'TAKEN'])
            ->assertUnprocessable()->assertJsonValidationErrors(['with_sav' => 'Insufficient saving balance']);
        $this->postJson("/api/v1/savings/customers/{$customer->id}/withdrawals", ['with_sav' => 20000, 'action' => 'TAKEN'])->assertCreated()->assertJsonPath('message', 'Saving Withdrawal successfully');

        $this->assertEquals(30000, $ledger->balance($admin->company_id, Account::HqSaving, $admin->branch_id));
        $this->assertEquals(30000, $ledger->balance($admin->company_id, Account::SavingsDeposits, $admin->branch_id));

        $this->getJson("/api/v1/savings/customers/{$customer->id}")->assertOk()
            ->assertJsonPath('data.total_saving', 30000)
            ->assertJsonPath('data.statement.0.description', 'SAVING DEPOSIT')
            ->assertJsonPath('data.statement.1.description', 'SAVING TAKEN')
            ->assertJsonPath('data.statement.1.balance', 30000);
        $this->getJson('/api/v1/savings/deposits')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', 50000);
        $this->getJson('/api/v1/savings/deposits?branch_id=all&from=2000-01-01&to=2000-01-01')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/savings/withdrawals')->assertOk()->assertJsonPath('data.0.withdrawal_type', 'TAKEN');
        $this->getJson('/api/v1/savings/balances')->assertOk()->assertJsonPath('data.0.amount', 30000);
        $this->getJson('/api/v1/savings/branch-balances')->assertOk()->assertJsonPath('data.0.amount', 30000);
    }

    public function test_withdrawal_validation(): void
    {
        $admin = $this->signInAdmin();
        $customer = Customer::factory()->create(['branch_id' => $admin->branch_id]);

        $this->postJson("/api/v1/savings/customers/{$customer->id}/withdrawals", ['action' => 'CLEAR'])
            ->assertUnprocessable()->assertJsonValidationErrors(['with_sav', 'method']);
        $this->postJson("/api/v1/savings/customers/{$customer->id}/deposits", [])->assertUnprocessable()->assertJsonValidationErrors('dep_sav');
    }

    public function test_clear_loan_withdrawal_repays_the_active_loan(): void
    {
        $admin = $this->signInAdmin();
        $customer = Customer::factory()->create(['branch_id' => $admin->branch_id]);
        $loan = Loan::factory()->create(['customer_id' => $customer->id, 'status' => LoanStatus::Active, 'amount_approved' => 100000, 'interest_amount' => 30000, 'insurance' => 0]);
        $ledger = app(Ledger::class);

        $this->postJson("/api/v1/savings/customers/{$customer->id}/deposits", ['dep_sav' => 60000])->assertCreated();
        $this->postJson("/api/v1/savings/customers/{$customer->id}/withdrawals", ['with_sav' => 40000, 'action' => 'CLEAR', 'method' => 'CASH'])->assertCreated();

        $this->assertEquals(20000, $ledger->balance($admin->company_id, Account::HqSaving, $admin->branch_id));
        $this->assertEquals(60000, app(LoanService::class)->outstanding($loan->fresh())['principal']);
        $this->assertSame($loan->id, Saving::where('withdrawal_type', 'CLEAR')->value('loan_id'));
        $this->getJson('/api/v1/savings/withdrawals')->assertOk()->assertJsonPath('data.0.description', 'SAVING CLEAR LOAN');

        $clear = Saving::where('withdrawal_type', 'CLEAR')->firstOrFail();
        $this->asApprover($admin, fn () => $this->postJson("/api/v1/savings/transactions/{$clear->id}/reverse", ['reason' => 'x'])->assertUnprocessable());

        $other = Customer::factory()->create(['branch_id' => $admin->branch_id]);
        $this->postJson("/api/v1/savings/customers/{$other->id}/deposits", ['dep_sav' => 1000])->assertCreated();
        $this->postJson("/api/v1/savings/customers/{$other->id}/withdrawals", ['with_sav' => 500, 'action' => 'CLEAR', 'method' => 'CASH'])
            ->assertUnprocessable()->assertJsonValidationErrors('action');
    }

    public function test_deposit_can_be_reversed(): void
    {
        $admin = $this->signInAdmin();
        $customer = Customer::factory()->create(['branch_id' => $admin->branch_id]);
        // Recorded by Finance (not the Super Admin) so the rule 6 reversal block below applies.
        $recorder = $this->secondApprover($admin, 'finance');
        $this->actingAs($recorder);

        $this->postJson("/api/v1/savings/customers/{$customer->id}/deposits", ['dep_sav' => 50000])->assertCreated();
        $saving = Saving::firstOrFail();

        // Rule 6: the employee who recorded the deposit does not reverse it.
        $this->postJson("/api/v1/savings/transactions/{$saving->id}/reverse", ['reason' => 'Wrong customer'])->assertForbidden();
        $this->asApprover($recorder, fn () => $this->postJson("/api/v1/savings/transactions/{$saving->id}/reverse", ['reason' => 'Wrong customer'])->assertOk()->assertJsonPath('message', 'Transaction Reversed successfully'));

        $this->assertEquals(0, app(Ledger::class)->balance($admin->company_id, Account::HqSaving, $admin->branch_id));
        $this->getJson("/api/v1/savings/customers/{$customer->id}")->assertOk()->assertJsonPath('data.total_saving', 0)->assertJsonPath('data.statement.0.reversed', true);
        $this->assertDatabaseCount('savings', 1);
    }

    public function test_permission_branch_scope_and_company_isolation(): void
    {
        $admin = $this->signInAdmin();
        $otherBranch = Branch::factory()->create(['company_id' => $admin->company_id]);
        $own = Customer::factory()->create(['branch_id' => $admin->branch_id]);
        $elsewhere = Customer::factory()->create(['branch_id' => $otherBranch->id]);
        $this->postJson("/api/v1/savings/customers/{$own->id}/deposits", ['dep_sav' => 1000])->assertCreated();
        $this->postJson("/api/v1/savings/customers/{$elsewhere->id}/deposits", ['dep_sav' => 2000])->assertCreated();

        $foreign = Customer::factory()->create();
        $this->postJson("/api/v1/savings/customers/{$foreign->id}/deposits", ['dep_sav' => 1000])->assertNotFound();

        $teller = Employee::factory()->create([
            'company_id' => $admin->company_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->company->roles()->where('key', 'teller')->value('id'),
        ]);
        $this->actingAs($teller);
        $this->getJson('/api/v1/savings/deposits')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/savings/customers/{$elsewhere->id}")->assertForbidden();
        $this->postJson('/api/v1/savings/transactions/'.Saving::first()->id.'/reverse', ['reason' => 'x'])->assertForbidden();

        $loanOfficer = Employee::factory()->create([
            'company_id' => $admin->company_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->company->roles()->where('key', 'loan_officer')->value('id'),
        ]);
        $this->actingAs($loanOfficer);
        $this->getJson('/api/v1/savings/balances')->assertForbidden();
        $this->postJson("/api/v1/savings/customers/{$own->id}/withdrawals", [])->assertForbidden();
    }
}

<?php

namespace Tests\Feature\Api\Expenses;

use App\Enums\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DividendDeclaration;
use App\Models\Employee;
use App\Models\ExpenseRequest;
use App\Models\ExpenseType;
use App\Models\JournalEntry;
use App\Services\Ledger;
use App\Services\PeriodClose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

class ExpenseReversalApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->ledger = app(Ledger::class);
    }

    public function test_branch_expense_reversal_restores_petty_cash_expense_and_period_profit(): void
    {
        $type = $this->type('branch', 'umeme');
        $this->ledger->openingBalance($this->admin->company_id, Account::PettyCash, 100000, branch: $this->admin->branch_id);
        $expense = $this->accepted('branch', $type, 45000, $this->employeeWithRole('finance'));
        $this->assertSame(55000.0, $this->ledger->balance($this->admin->company_id, Account::PettyCash, $this->admin->branch_id));
        $this->assertSame(45000.0, $this->branchExpenses());

        $finance = $this->employeeWithRole('finance');
        $this->actingAs($finance);
        $this->getJson('/api/v1/expenses/requests?scope=branch&status=accepted')->assertOk()->assertJsonPath('data.0.can_reverse', true);
        $this->postJson("/api/v1/expenses/requests/{$expense->id}/reverse", ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/expenses/requests/{$expense->id}/reverse", ['reason' => 'Paid twice'])->assertOk()
            ->assertJsonPath('message', 'Expenses Reversed successfully')
            ->assertJsonPath('data.status', 'reversed')
            ->assertJsonPath('notice', null);

        $this->assertSame(100000.0, $this->ledger->balance($this->admin->company_id, Account::PettyCash, $this->admin->branch_id));
        $this->assertSame(0.0, $this->ledger->balance($this->admin->company_id, Account::OperatingExpense, $this->admin->branch_id));
        $this->assertSame(0.0, $this->branchExpenses());

        $expense->refresh();
        $this->assertSame(45000.0, (float) $expense->amount);
        $this->assertNotNull($expense->approved_at);
        $this->assertSame($finance->id, $expense->reversed_by);
        $this->assertSame('Paid twice', $expense->reversal_reason);
        $this->assertSame($expense->journal_entry_id, JournalEntry::findOrFail($expense->reversal_journal_entry_id)->reversal_of_id);
        $this->assertTrue(AuditLog::where('action', 'ExpenseRequest.reversed')->where('auditable_id', $expense->id)->exists());

        $this->getJson('/api/v1/expenses/requests?scope=branch&status=accepted')->assertOk()
            ->assertJsonPath('data.0.status', 'reversed')
            ->assertJsonPath('data.0.reversal_reason', 'Paid twice')
            ->assertJsonPath('data.0.reversed_by', $finance->full_name)
            ->assertJsonPath('total', 0)
            ->assertJsonPath('total_reversed', 45000);
        $this->getJson('/api/v1/expenses/requests?scope=branch&status=reversed')->assertOk()->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/expenses/requests/{$expense->id}/reverse", ['reason' => 'Again'])->assertUnprocessable()->assertJsonPath('errors.reason.0', 'This expense has already been reversed.');
        $this->deleteJson("/api/v1/expenses/requests/{$expense->id}")->assertUnprocessable();
    }

    public function test_hq_and_bank_expense_reversals_return_money_to_the_paying_account(): void
    {
        $this->ledger->openingBalance($this->admin->company_id, Account::HqInterest, 50000);
        $hq = $this->pending('hq', $this->type('hq', 'MAFUTA'), 20000);
        $this->approveAsSecondUser($this->admin, "/api/v1/expenses/requests/{$hq->id}/accept", ['from_account' => Account::HqInterest->value]);

        $bank = BankAccount::create(['company_id' => $this->admin->company_id, 'name' => 'NMB']);
        $this->ledger->openingBalance($this->admin->company_id, Account::Bank, 16200, bankAccount: $bank);
        $bankExpense = ExpenseRequest::create([
            'company_id' => $this->admin->company_id, 'scope' => 'bank', 'bank_account_id' => $bank->id, 'expense_type_id' => $this->type('bank', 'ADA')->id,
            'amount' => 6200, 'status' => 'pending', 'request_date' => today(),
        ]);
        $this->approveAsSecondUser($this->admin, "/api/v1/expenses/requests/{$bankExpense->id}/accept");

        $this->postJson("/api/v1/expenses/requests/{$hq->id}/reverse", ['reason' => 'Wrong account'])->assertOk();
        $this->postJson("/api/v1/expenses/requests/{$bankExpense->id}/reverse", ['reason' => 'Bank refunded'])->assertOk();

        $this->assertSame(50000.0, $this->ledger->balance($this->admin->company_id, Account::HqInterest));
        $this->assertSame(16200.0, $bank->balance());
        $this->assertSame(0.0, $this->ledger->balance($this->admin->company_id, Account::OperatingExpense, allBranches: true));
    }

    public function test_closed_period_with_dividend_declaration_blocks_and_without_distributions_posts_today(): void
    {
        $type = $this->type('branch', 'KODI');
        $this->travelTo(now()->subMonth()->startOfMonth()->addDays(2));
        $this->ledger->openingBalance($this->admin->company_id, Account::PettyCash, 100000, branch: $this->admin->branch_id);
        $approver = $this->secondApprover($this->admin);
        $blocked = $this->accepted('branch', $type, 10000, $approver);
        $allowed = $this->accepted('branch', $type, 5000, $approver);
        $month = now()->startOfMonth();
        $this->travelBack();
        $period = AccountingPeriod::create(['company_id' => $this->admin->company_id, 'period_start' => $month->toDateString(), 'period_end' => $month->copy()->endOfMonth()->toDateString(), 'status' => 'closed']);

        $this->getJson('/api/v1/expenses/requests?scope=branch&status=accepted&branch_id=all')->assertOk()->assertJsonPath('data.0.can_reverse', true);
        $notice = 'The period '.$month->format('F Y').' is closed; the reversal is recorded as an adjustment in the current period ('.now()->format('F Y').').';
        $this->postJson("/api/v1/expenses/requests/{$allowed->id}/reverse", ['reason' => 'Refunded'])->assertOk()->assertJsonPath('notice', $notice);
        $this->assertSame(today()->toDateString(), JournalEntry::findOrFail($allowed->fresh()->reversal_journal_entry_id)->entry_date->toDateString());

        DividendDeclaration::create(['company_id' => $this->admin->company_id, 'period' => $period->period_start->toDateString(), 'profit_amount' => 1, 'reinvest_percent' => 70, 'reinvest_amount' => 0.7, 'dividend_percent' => 30, 'dividend_amount' => 0.3]);
        $message = 'The profit of '.$month->format('F Y').' has already been distributed (dividend declaration); this reversal would change a distributed period and is blocked.';
        $this->getJson('/api/v1/expenses/requests?scope=branch&status=accepted&branch_id=all')->assertOk()->assertJsonPath('data.1.id', $blocked->id)->assertJsonPath('data.1.reverse_blocked_reason', $message);

        $entries = JournalEntry::count();
        $this->postJson("/api/v1/expenses/requests/{$blocked->id}/reverse", ['reason' => 'Late'])->assertUnprocessable()->assertJsonPath('errors.reason.0', $message);
        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame('accepted', $blocked->fresh()->status);
    }

    public function test_permissions_company_isolation_and_rollback(): void
    {
        $real = $this->ledger;
        $this->partialMock(Ledger::class, function ($mock) use ($real): void {
            $mock->shouldReceive('reverse')->andReturnUsing(function (JournalEntry $entry, string $reason) use ($real): never {
                $real->reverse($entry, $reason);

                throw new RuntimeException('Ledger failure');
            });
        });

        $type = $this->type('branch', 'MAJI');
        $this->ledger->openingBalance($this->admin->company_id, Account::PettyCash, 900000, branch: $this->admin->branch_id);
        $approver = $this->secondApprover($this->admin);
        $large = $this->accepted('branch', $type, 600000, $approver);
        $small = $this->accepted('branch', $type, 1000, $approver);

        $this->actingAs($this->employeeWithRole('finance'));
        $this->postJson("/api/v1/expenses/requests/{$large->id}/reverse", ['reason' => 'Too big for finance'])->assertForbidden();
        $this->actingAs($this->employeeWithRole('admin'));
        $this->getJson('/api/v1/expenses/requests?scope=branch&status=accepted&branch_id=all')->assertOk()->assertJsonPath('data.0.can_reverse', false)->assertJsonPath('data.0.reverse_blocked_reason', null);
        $this->postJson("/api/v1/expenses/requests/{$small->id}/reverse", ['reason' => 'No accounting.reverse'])->assertForbidden();
        $this->actingAs($this->employeeWithRole('branch_manager'));
        $this->postJson("/api/v1/expenses/requests/{$small->id}/reverse", ['reason' => 'Manager'])->assertForbidden();

        $foreignBranch = Branch::factory()->create(['company_id' => Company::factory()->create()->id]);
        $foreign = ExpenseRequest::create([
            'company_id' => $foreignBranch->company_id, 'scope' => 'branch', 'branch_id' => $foreignBranch->id,
            'expense_type_id' => ExpenseType::create(['company_id' => $foreignBranch->company_id, 'scope' => 'branch', 'name' => 'x'])->id,
            'amount' => 1000, 'status' => 'accepted', 'request_date' => today(),
        ]);
        $this->actingAs($this->admin);
        $this->postJson("/api/v1/expenses/requests/{$foreign->id}/reverse", ['reason' => 'Foreign'])->assertNotFound();

        $pending = $this->pending('branch', $type, 500);
        $this->postJson("/api/v1/expenses/requests/{$pending->id}/reverse", ['reason' => 'Pending'])->assertUnprocessable()->assertJsonPath('errors.reason.0', 'Only accepted expenses can be reversed.');

        $entries = JournalEntry::count();

        $this->withoutExceptionHandling();
        try {
            $this->postJson("/api/v1/expenses/requests/{$small->id}/reverse", ['reason' => 'Mistake']);
            $this->fail('The posting failure must surface.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Ledger failure', $exception->getMessage());
        }

        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame('accepted', $small->fresh()->status);
        $this->assertSame(299000.0, $real->balance($this->admin->company_id, Account::PettyCash, $this->admin->branch_id));
    }

    public function test_accept_rejects_non_positive_amounts_and_posts_once(): void
    {
        $type = $this->type('branch', 'SODA');
        $this->ledger->openingBalance($this->admin->company_id, Account::PettyCash, 10000, branch: $this->admin->branch_id);
        $request = $this->pending('branch', $type, 1000);

        $this->postJson("/api/v1/expenses/requests/{$request->id}/accept", ['req_amount' => 0])->assertUnprocessable()->assertJsonValidationErrors('req_amount');
        $this->postJson("/api/v1/expenses/requests/{$request->id}/accept")->assertOk();
        $entries = JournalEntry::count();
        $this->postJson("/api/v1/expenses/requests/{$request->id}/accept")->assertUnprocessable();
        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame(9000.0, $this->ledger->balance($this->admin->company_id, Account::PettyCash, $this->admin->branch_id));
    }

    private function branchExpenses(): float
    {
        $period = app(PeriodClose::class)->calculate($this->admin->company_id, now());

        return (float) $period->results->firstWhere('branch_id', $this->admin->branch_id)?->expenses;
    }

    private function accepted(string $scope, ExpenseType $type, float $amount, Employee $approver): ExpenseRequest
    {
        $request = $this->pending($scope, $type, $amount);
        $this->actingAs($approver)->postJson("/api/v1/expenses/requests/{$request->id}/accept")->assertOk();
        $this->actingAs($this->admin);

        return $request->fresh();
    }

    private function type(string $scope, string $name): ExpenseType
    {
        return ExpenseType::create(['company_id' => $this->admin->company_id, 'scope' => $scope, 'name' => $name]);
    }

    private function pending(string $scope, ExpenseType $type, float $amount): ExpenseRequest
    {
        return ExpenseRequest::create([
            'company_id' => $this->admin->company_id, 'scope' => $scope, 'branch_id' => $scope === 'branch' ? $this->admin->branch_id : null,
            'expense_type_id' => $type->id, 'amount' => $amount, 'description' => 'test', 'status' => 'pending', 'request_date' => today(),
        ]);
    }

    private function employeeWithRole(string $key): Employee
    {
        return Employee::factory()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->admin->branch_id,
            'role_id' => $this->admin->company->roles()->where('key', $key)->value('id'),
        ]);
    }
}

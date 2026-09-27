<?php

namespace Tests\Feature\Api\Expenses;

use App\Enums\Account;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ExpenseRequest;
use App\Models\ExpenseType;
use App\Models\LedgerAccount;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

class ExpenseApiTest extends TestCase
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

    public function test_expense_types_crud_per_register(): void
    {
        $this->postJson('/api/v1/expenses/types', ['scope' => 'branch', 'ex_name' => 'umeme'])->assertCreated()->assertJsonPath('message', 'Expenses Registered successfully');
        $this->postJson('/api/v1/expenses/types', ['scope' => 'hq', 'exp_desc' => 'KODI'])->assertCreated();
        $this->postJson('/api/v1/expenses/types', ['scope' => 'bank', 'expenses_name' => 'MISHAHARA'])->assertCreated();
        $this->postJson('/api/v1/expenses/types', ['scope' => 'hq', 'ex_name' => 'wrong field'])->assertUnprocessable()->assertJsonValidationErrors('exp_desc');

        $this->getJson('/api/v1/expenses/types?scope=branch')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'umeme');
        $this->getJson('/api/v1/expenses/options/types?scope=hq')->assertOk()->assertJsonPath('data.0.label', 'KODI');

        $type = ExpenseType::where('scope', 'bank')->firstOrFail();
        $this->putJson("/api/v1/expenses/types/{$type->id}", ['expenses_name' => 'MISHAHARA MAPYA'])->assertOk();
        $this->assertSame('MISHAHARA MAPYA', $type->fresh()->name);
        $this->deleteJson("/api/v1/expenses/types/{$type->id}")->assertOk();
        $this->assertModelMissing($type);
    }

    public function test_small_branch_expense_is_approved_by_finance_and_paid_from_branch_petty_cash(): void
    {
        $type = $this->type('branch', 'umeme');
        $this->ledger->openingBalance($this->admin->company_id, Account::PettyCash, 100000, branch: $this->admin->branch_id);
        $manager = $this->employeeWithRole('branch_manager');
        $this->actingAs($manager);

        $this->postJson('/api/v1/expenses/requests', [
            'scope' => 'branch', 'blanch_id' => $this->admin->branch_id, 'ex_id' => $type->id, 'req_amount' => 50000, 'req_description' => 'kulipa umeme',
        ])->assertCreated()->assertJsonPath('message', 'Expenses Requested successfully');

        $request = ExpenseRequest::firstOrFail();
        $this->postJson("/api/v1/expenses/requests/{$request->id}/accept", [])->assertForbidden();

        $this->actingAs($this->employeeWithRole('finance'));
        $this->getJson('/api/v1/expenses/requests?scope=branch')->assertOk()->assertJsonPath('data.0.can_approve', true)->assertJsonPath('data.0.approval_level', 'finance');
        $this->postJson("/api/v1/expenses/requests/{$request->id}/accept", ['req_comment' => 'sawa', 'req_amount' => 45000])->assertOk()->assertJsonPath('message', 'Expenses Accepted successfully');

        $request->refresh();
        $this->assertSame('accepted', $request->status);
        $this->assertSame('sawa', $request->comment);
        $this->assertSame(Account::PettyCash->value, $request->paid_from_account);
        $this->assertNotNull($request->journal_entry_id);
        $this->assertSame(55000.0, $this->ledger->balance($this->admin->company_id, Account::PettyCash, $this->admin->branch_id));
        $this->assertTrue(LedgerAccount::where('key', Account::OperatingExpense->value)->where('branch_id', $this->admin->branch_id)->where('expense_type_id', $type->id)->exists());

        $this->getJson('/api/v1/expenses/requests?scope=branch&status=accepted&branch_id=all')->assertOk()->assertJsonPath('data.0.amount', 45000)->assertJsonPath('total', 45000);
        $this->postJson("/api/v1/expenses/requests/{$request->id}/accept")->assertUnprocessable();
    }

    public function test_large_branch_expense_requires_admin_and_threshold_is_a_company_setting(): void
    {
        $type = $this->type('branch', 'KODI');
        $this->ledger->openingBalance($this->admin->company_id, Account::PettyCash, 2000000, branch: $this->admin->branch_id);
        $request = $this->pending('branch', $type, 600000);

        $this->getJson('/api/v1/expenses/settings')->assertOk()->assertJsonPath('data.expense_approval_limit', 500000);

        $this->actingAs($this->employeeWithRole('finance'));
        $this->postJson("/api/v1/expenses/requests/{$request->id}/accept")->assertForbidden();
        // §34 ruling: Finance cannot get under the Admin threshold by approving less than was requested.
        $this->postJson("/api/v1/expenses/requests/{$request->id}/accept", ['req_amount' => 400000])->assertForbidden();
        $this->assertSame('pending', $request->fresh()->status);
        $this->putJson('/api/v1/expenses/settings', ['expense_approval_limit' => 1000])->assertForbidden();

        $this->actingAs($this->admin);
        $this->putJson('/api/v1/expenses/settings', ['expense_approval_limit' => -1])->assertUnprocessable();
        $this->putJson('/api/v1/expenses/settings', ['expense_approval_limit' => 1000000])->assertOk();
        $this->assertTrue(AuditLog::where('action', 'Company.expense_approval_limit')->exists());

        $this->actingAs($this->employeeWithRole('finance'));
        $this->postJson("/api/v1/expenses/requests/{$request->id}/accept")->assertOk();
        $this->assertSame(1400000.0, $this->ledger->balance($this->admin->company_id, Account::PettyCash, $this->admin->branch_id));
    }

    public function test_branch_expense_fails_when_the_petty_cash_balance_is_insufficient(): void
    {
        $this->ledger->openingBalance($this->admin->company_id, Account::Principal, 900000, branch: $this->admin->branch_id);
        $request = $this->pending('branch', $this->type('branch', 'SODA'), 12000);

        $this->postJson("/api/v1/expenses/requests/{$request->id}/accept")->assertUnprocessable()->assertJsonValidationErrors('req_amount');
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(900000.0, $this->ledger->balance($this->admin->company_id, Account::Principal, $this->admin->branch_id));
    }

    public function test_hq_expense_is_approved_by_admin_and_paid_from_hq_account_never_branch_petty_cash(): void
    {
        $type = $this->type('hq', 'MAFUTA');
        $this->ledger->openingBalance($this->admin->company_id, Account::Interest, 100000, branch: $this->admin->branch_id);
        $this->ledger->openingBalance($this->admin->company_id, Account::HqInterest, 50000);
        $requester = $this->employeeWithRole('admin');
        $this->actingAs($requester);

        $this->postJson('/api/v1/expenses/requests', [
            'scope' => 'hq', 'blanch_id' => $this->admin->branch_id, 'ex_id' => $type->id, 'req_amount' => 20000, 'req_description' => 'gari',
        ])->assertCreated();
        $request = ExpenseRequest::firstOrFail();
        $this->assertSame($this->admin->branch_id, $request->branch_id);

        $this->actingAs($this->employeeWithRole('finance'));
        $this->getJson('/api/v1/expenses/requests?scope=hq')->assertOk()->assertJsonPath('data.0.can_approve', false)->assertJsonPath('data.0.staff', $requester->full_name);
        $this->postJson("/api/v1/expenses/requests/{$request->id}/accept")->assertForbidden();

        $this->actingAs($requester);
        $this->postJson("/api/v1/expenses/requests/{$request->id}/accept", ['from_account' => Account::Interest->value])->assertUnprocessable()->assertJsonValidationErrors('from_account');
        // Rule 6: the requesting admin cannot accept it; another admin does.
        $this->postJson("/api/v1/expenses/requests/{$request->id}/accept", ['from_account' => Account::HqInterest->value])->assertForbidden();
        $this->approveAsSecondUser($requester, "/api/v1/expenses/requests/{$request->id}/accept", ['from_account' => Account::HqInterest->value]);

        $this->assertSame(30000.0, $this->ledger->balance($this->admin->company_id, Account::HqInterest));
        $this->assertSame(100000.0, $this->ledger->balance($this->admin->company_id, Account::Interest, $this->admin->branch_id));
        $this->getJson('/api/v1/expenses/requests?scope=hq&status=accepted')->assertOk()->assertJsonPath('data.0.paid_from', 'INTEREST ACCOUNT')->assertJsonPath('data.0.branch', $this->admin->branch->name);
    }

    public function test_hq_expense_defaults_to_company_account(): void
    {
        $request = $this->pending('hq', $this->type('hq', 'KODI'), 20000);

        $this->postJson("/api/v1/expenses/requests/{$request->id}/accept")->assertUnprocessable();

        $this->ledger->openingBalance($this->admin->company_id, Account::Company, 25000);
        $this->postJson("/api/v1/expenses/requests/{$request->id}/accept")->assertOk();
        $this->assertSame(5000.0, $this->ledger->balance($this->admin->company_id, Account::Company));
    }

    public function test_bank_expense_debits_the_bank_account(): void
    {
        $type = $this->type('bank', 'MISHAHARA');
        $bank = BankAccount::create(['company_id' => $this->admin->company_id, 'name' => 'NMB']);
        $this->ledger->openingBalance($this->admin->company_id, Account::Bank, 16200, bankAccount: $bank);

        $this->postJson('/api/v1/expenses/requests', ['scope' => 'bank', 'ac_id' => $bank->id, 'exp_id' => $type->id, 'amount' => 6200, 'comment' => 'mshahara'])->assertCreated();
        $request = ExpenseRequest::firstOrFail();

        $this->approveAsSecondUser($this->admin, "/api/v1/expenses/requests/{$request->id}/accept");
        $this->assertSame(10000.0, $bank->balance());
        $this->getJson('/api/v1/expenses/requests?scope=bank&status=all')->assertOk()->assertJsonPath('data.0.bank_account', 'NMB');
    }

    public function test_validation_and_role_permissions(): void
    {
        $this->postJson('/api/v1/expenses/requests', ['scope' => 'branch'])->assertUnprocessable()->assertJsonValidationErrors(['blanch_id', 'ex_id', 'req_amount', 'req_description']);

        $this->actingAs($this->employeeWithRole('teller'));
        $this->getJson('/api/v1/expenses/requests?scope=branch')->assertForbidden();
        $this->postJson('/api/v1/expenses/requests', ['scope' => 'branch'])->assertForbidden();
        $this->postJson('/api/v1/expenses/types', ['scope' => 'hq', 'exp_desc' => 'x'])->assertForbidden();
    }

    public function test_hq_finance_uses_hq_expense_categories_but_only_admins_register_them(): void
    {
        $type = $this->type('hq', 'KODI');
        $finance = $this->employeeWithRole('finance');
        $this->assertTrue($finance->can('hq.manage'));
        $this->actingAs($finance);

        $this->getJson('/api/v1/expenses/options/types?scope=hq')->assertOk()->assertJsonPath('data.0.label', 'KODI');
        $this->postJson('/api/v1/expenses/types', ['scope' => 'hq', 'exp_desc' => 'MAFUTA'])->assertForbidden();
        $this->putJson("/api/v1/expenses/types/{$type->id}", ['exp_desc' => 'KODI MPYA'])->assertForbidden();
        $this->deleteJson("/api/v1/expenses/types/{$type->id}")->assertForbidden();
    }

    public function test_head_office_is_not_a_branch_for_branch_expenses(): void
    {
        $hq = Branch::factory()->create(['company_id' => $this->admin->company_id, 'is_head_office' => true]);
        $type = $this->type('branch', 'MAJI');

        $options = collect($this->getJson('/api/v1/options/branches?branches_only=1&with_all=1')->assertOk()->json('data'))->pluck('value');
        $this->assertNotContains((string) $hq->id, $options);
        $this->assertContains((string) $this->admin->branch_id, $options);
        $this->assertContains((string) $hq->id, collect($this->getJson('/api/v1/options/branches')->json('data'))->pluck('value'), 'other lists still show Head Office');

        $this->postJson('/api/v1/expenses/requests', ['scope' => 'branch', 'blanch_id' => $hq->id, 'ex_id' => $type->id, 'req_amount' => 1000, 'req_description' => 'maji'])
            ->assertUnprocessable()->assertJsonValidationErrors('blanch_id');
    }

    public function test_branch_scope_isolation_and_rejection(): void
    {
        $otherBranch = Branch::factory()->create(['company_id' => $this->admin->company_id]);
        $type = $this->type('branch', 'MAJI');
        $foreign = $this->pending('branch', $type, 1000, $otherBranch->id);
        $own = $this->pending('branch', $type, 2000);

        $this->actingAs($this->employeeWithRole('branch_manager'));
        $this->getJson('/api/v1/expenses/requests?scope=branch')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->postJson('/api/v1/expenses/requests', ['scope' => 'branch', 'blanch_id' => $otherBranch->id, 'ex_id' => $type->id, 'req_amount' => 10, 'req_description' => 'x'])->assertForbidden();
        $this->deleteJson("/api/v1/expenses/requests/{$foreign->id}")->assertForbidden();
        $this->deleteJson("/api/v1/expenses/requests/{$own->id}")->assertForbidden();

        $this->actingAs($this->admin);
        $this->deleteJson("/api/v1/expenses/requests/{$own->id}")->assertOk();
        $this->assertModelMissing($own);
    }

    public function test_foreign_company_records_are_not_reachable(): void
    {
        $foreignBranch = Branch::factory()->create(['company_id' => Company::factory()->create()->id]);
        $foreignType = ExpenseType::create(['company_id' => $foreignBranch->company_id, 'scope' => 'branch', 'name' => 'umeme']);
        $request = ExpenseRequest::create([
            'company_id' => $foreignBranch->company_id, 'scope' => 'branch', 'branch_id' => $foreignBranch->id,
            'expense_type_id' => $foreignType->id, 'amount' => 1000, 'status' => 'pending', 'request_date' => today(),
        ]);

        $this->postJson("/api/v1/expenses/requests/{$request->id}/accept")->assertNotFound();
        $this->deleteJson("/api/v1/expenses/types/{$foreignType->id}")->assertNotFound();
    }

    private function type(string $scope, string $name): ExpenseType
    {
        return ExpenseType::create(['company_id' => $this->admin->company_id, 'scope' => $scope, 'name' => $name]);
    }

    private function pending(string $scope, ExpenseType $type, float $amount, ?int $branchId = null): ExpenseRequest
    {
        return ExpenseRequest::create([
            'company_id' => $this->admin->company_id, 'scope' => $scope, 'branch_id' => $scope === 'branch' ? ($branchId ?? $this->admin->branch_id) : null,
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

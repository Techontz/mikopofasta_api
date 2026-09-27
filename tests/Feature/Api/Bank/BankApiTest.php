<?php

namespace Tests\Feature\Api\Bank;

use App\Enums\Account;
use App\Models\BankAccount;
use App\Models\Employee;
use App\Models\SalaryPayment;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankApiTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->ledger = app(Ledger::class);
    }

    public function test_account_is_registered_with_opening_balance_updated_listed_and_deleted(): void
    {
        $this->postJson('/api/v1/bank/accounts', ['ac_name' => 'NMB', 'opening_balance' => 16200])
            ->assertCreated()
            ->assertJsonPath('message', 'Account Registered successfully');

        $account = BankAccount::firstOrFail();
        $this->assertSame(16200.0, $account->balance());
        $this->assertSame(16200.0, $this->ledger->balance($this->admin->company_id, Account::Capital));

        $this->putJson("/api/v1/bank/accounts/{$account->id}", ['ac_name' => 'NMB BANK'])->assertOk()->assertJsonPath('data.name', 'NMB BANK');
        $this->getJson('/api/v1/bank/balances')->assertOk()->assertJsonPath('data.0.balance', 16200)->assertJsonPath('total', 16200);
        $this->getJson('/api/v1/bank/options/accounts')->assertOk()->assertJsonPath('data.0.label', 'NMB BANK - 16,200');

        $this->deleteJson("/api/v1/bank/accounts/{$account->id}")->assertUnprocessable();

        $empty = BankAccount::create(['company_id' => $this->admin->company_id, 'name' => 'CRDB']);
        $this->deleteJson("/api/v1/bank/accounts/{$empty->id}")->assertOk();
        $this->assertModelMissing($empty);
    }

    public function test_validation_and_permission(): void
    {
        $this->postJson('/api/v1/bank/accounts', [])->assertUnprocessable()->assertJsonValidationErrors('ac_name');

        $this->actingAs($this->employeeWithRole('loan_officer'));
        $this->getJson('/api/v1/bank/accounts')->assertForbidden();
        $this->postJson('/api/v1/bank/company-transfers', [])->assertForbidden();
    }

    public function test_a_bank_never_funds_a_branch_or_the_removed_hq_salary_advance_and_disbursement_accounts(): void
    {
        $bank = BankAccount::create(['company_id' => $this->admin->company_id, 'name' => 'NMB']);

        $this->postJson('/api/v1/bank/to-branch', ['from_account' => $bank->id, 'to_blanch' => $this->admin->branch_id, 'amount' => 50000, 'charger_fee' => 1000])->assertNotFound();
        $this->postJson('/api/v1/bank/to-hq', ['from_acc' => $bank->id, 'amount' => 40000, 'to_acc' => 'salary', 'charger_fee' => 500])->assertNotFound();
        $this->getJson('/api/v1/bank/to-hq')->assertNotFound();
    }

    /**
     * A branch holds no money of its own beyond the petty cash HQ sends it, so there is nothing at a branch to sweep
     * into a company bank account: the Bank Transaction / Approved Transaction screens and their routes are gone.
     */
    public function test_the_branch_to_bank_sweep_is_gone(): void
    {
        $bank = BankAccount::create(['company_id' => $this->admin->company_id, 'name' => 'NMB']);

        $this->getJson('/api/v1/bank/transfers')->assertNotFound();
        $this->postJson('/api/v1/bank/transfers', [
            'from_blanch_id' => $this->admin->branch_id, 'ac_type' => Account::LoanFee->value, 'amount' => 1000, 'to_account_id' => $bank->id,
        ])->assertNotFound();
        $this->getJson('/api/v1/bank/options/branch-accounts')->assertNotFound();
    }

    public function test_a_branch_role_never_reaches_bank_money_even_when_granted_bank_manage(): void
    {
        $bank = BankAccount::create(['company_id' => $this->admin->company_id, 'name' => 'NMB']);
        $role = $this->admin->company->roles()->where('key', 'branch_manager')->firstOrFail();
        $role->permissions()->create(['permission' => 'bank.manage']);
        $this->actingAs($this->employeeWithRole('branch_manager'));

        $this->postJson('/api/v1/bank/company-transfers', [
            'direction' => 'company_to_bank', 'bank_account_id' => $bank->id, 'amount' => 100,
        ])->assertForbidden();
        $this->getJson('/api/v1/bank/company-transfers')->assertForbidden();
    }

    public function test_payroll_list_and_detail(): void
    {
        foreach ([84000, 8000] as $takeHome) {
            SalaryPayment::create([
                'company_id' => $this->admin->company_id, 'employee_id' => $this->admin->id, 'salary' => 100000,
                'take_home' => $takeHome, 'paid_from_account' => 'INTEREST A/C', 'paid_on' => '2024-05-21',
                'phone' => '0711', 'account_name' => 'CRDB', 'account_number' => '898657465',
            ]);
        }

        $this->getJson('/api/v1/bank/payroll')->assertOk()->assertJsonPath('data.0.amount', 92000)->assertJsonPath('data.0.date', '2024-05-21');
        $this->getJson('/api/v1/bank/payroll/2024-05-21')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.account_number', '898657465');
        $this->getJson('/api/v1/bank/payroll/not-a-date')->assertNotFound();

        $this->postJson('/api/v1/bank/payroll/pay')->assertStatus(405);
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

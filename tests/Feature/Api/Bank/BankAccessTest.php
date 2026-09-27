<?php

namespace Tests\Feature\Api\Bank;

use App\Enums\Account;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\ShareHolder;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bank accounts and bank transfers are company money: only Super Admin, Admin or a shareholder of the company may reach
 * them — no other role, even when the role or a staff privilege grants bank.manage. The two HQ funds movements Finance owns
 * (petty cash to a branch, HQ reserve to the Investment RESERVE A/C) sit behind funds.transfer instead and stay open to it.
 * funds.transfer is explicit-only: the owners approve those two requests, they never raise them, so the Super Admin does
 * not hold it and cannot send petty cash.
 */
class BankAccessTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
    }

    public function test_super_admin_and_admin_reach_the_bank_module(): void
    {
        $this->getJson('/api/v1/bank/accounts')->assertOk();
        $this->actingAs($this->staff('admin'))->getJson('/api/v1/bank/company-transfers')->assertOk();
    }

    public function test_finance_and_other_roles_are_refused_even_with_bank_manage_granted(): void
    {
        $finance = $this->staff('finance');
        $this->actingAs($finance)->getJson('/api/v1/bank/accounts')->assertForbidden();

        $finance->role->permissions()->firstOrCreate(['permission' => 'bank.manage']);
        $finance->permissionOverrides()->create(['permission' => 'bank.manage', 'granted' => true]);
        $finance = $finance->fresh();

        $this->assertFalse($finance->can('bank.manage'));
        $this->actingAs($finance)->getJson('/api/v1/bank/balances')->assertForbidden();
        $this->actingAs($finance)->getJson('/api/v1/bank/company-transfers')->assertForbidden();
    }

    public function test_finance_keeps_the_two_hq_funds_movements_it_owns(): void
    {
        $finance = $this->staff('finance');

        $this->actingAs($finance)->getJson('/api/v1/bank/petty-cash')->assertOk();
        $this->actingAs($finance)->getJson('/api/v1/bank/reserve-to-investment')->assertOk();
        // The owners' leg is not Finance's: it moves reserve already sent to the Investment.
        $this->actingAs($finance)->getJson('/api/v1/bank/reserve-to-principal')->assertForbidden();
    }

    public function test_only_finance_sends_petty_cash_the_owners_merely_read_and_approve_it(): void
    {
        $branchId = Branch::factory()->create(['company_id' => $this->admin->company_id])->id;
        app(Ledger::class)->openingBalance($this->admin->company_id, Account::Interest, 5000, branch: $branchId);
        $payload = ['branch_id' => $branchId, 'amount' => 1000];

        $this->assertNotContains('funds.transfer', $this->admin->permissionKeys(), 'never implied by the Super Admin role');
        $this->postJson('/api/v1/bank/petty-cash', $payload)->assertForbidden();
        $this->actingAs($this->staff('admin'))->postJson('/api/v1/bank/petty-cash', $payload)->assertForbidden();

        // The approver still reaches the list behind the Pending Approvals link.
        $this->actingAs($this->admin)->getJson('/api/v1/bank/petty-cash')->assertOk();
        $this->actingAs($this->staff('finance'))->postJson('/api/v1/bank/petty-cash', $payload)->assertCreated();
    }

    public function test_only_finance_sends_reserve_to_the_investment_the_owners_merely_read_and_approve_it(): void
    {
        app(Ledger::class)->openingBalance($this->admin->company_id, Account::Reserve, 5000, branch: $this->admin->branch_id);
        $payload = ['amount' => 1000];

        $this->postJson('/api/v1/bank/reserve-to-investment', $payload)->assertForbidden();
        $this->actingAs($this->staff('admin'))->postJson('/api/v1/bank/reserve-to-investment', $payload)->assertForbidden();

        // The approver still reaches the list behind the Pending Approvals link.
        $this->actingAs($this->admin)->getJson('/api/v1/bank/reserve-to-investment')->assertOk();
        $this->actingAs($this->staff('finance'))->postJson('/api/v1/bank/reserve-to-investment', $payload)->assertCreated();
    }

    public function test_a_staff_login_linked_to_a_shareholder_may_be_given_the_bank_module(): void
    {
        $finance = $this->staff('finance');
        $finance->permissionOverrides()->create(['permission' => 'bank.manage', 'granted' => true]);
        ShareHolder::create([
            'company_id' => $this->admin->company_id, 'employee_id' => $finance->id, 'first_name' => 'ASHA', 'last_name' => 'HOLDER',
            'mobile' => '0777', 'email' => 'asha@example.com', 'date_of_birth' => '1990-01-01',
        ]);

        $this->actingAs($finance->fresh())->getJson('/api/v1/bank/accounts')->assertOk();
    }

    private function staff(string $role): Employee
    {
        return Employee::factory()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->admin->branch_id,
            'role_id' => $this->admin->company->roles()->where('key', $role)->value('id'),
        ]);
    }
}

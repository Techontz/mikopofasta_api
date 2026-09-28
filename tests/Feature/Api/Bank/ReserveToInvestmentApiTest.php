<?php

namespace Tests\Feature\Api\Bank;

use App\Enums\Account;
use App\Models\BankTransfer;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\ShareHolder;
use App\Services\Ledger;
use App\Services\Shareholders\ShareholderAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

/**
 * All interest reserve belongs to HQ (the branch RESERVE A/C is only a report). HQ sends an amount of it to the Investment
 * RESERVE A/C; it leaves the HQ reserve and is added to the Investment only when a second authorised user approves.
 */
class ReserveToInvestmentApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    private Ledger $ledger;

    private Branch $otherBranch;

    /** Leg 1 is Finance's: the owners approve these requests, they never raise them. */
    private Employee $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->ledger = app(Ledger::class);
        $this->otherBranch = Branch::factory()->create(['company_id' => $this->admin->company_id]);
        $this->finance = $this->secondApprover($this->admin, 'finance');

        $this->ledger->openingBalance($this->admin->company_id, Account::Reserve, 300000, branch: $this->admin->branch_id);
        $this->ledger->openingBalance($this->admin->company_id, Account::Reserve, 100000, branch: $this->otherBranch);
    }

    public function test_hq_reserve_is_sent_to_the_investment_reserve_only_after_a_second_approval(): void
    {
        $companyId = $this->admin->company_id;

        $this->getJson('/api/v1/hq/balances')->assertOk()->assertJsonPath('data.3.name', 'RESERVE')->assertJsonPath('data.3.balance', 400000);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.account_balances.Reserve A/C', 0);

        $this->actAs($this->finance);
        $id = $this->postJson('/api/v1/bank/reserve-to-investment', ['amount' => 200000])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->assertSame(0.0, $this->ledger->balance($companyId, Account::InvestmentReserve), 'a pending transfer moves nothing');
        // Finance raises and stops there: deciding — and even the approvals queue — belongs to the owners.
        $this->postJson("/api/v1/bank/transfers/{$id}/approve")->assertForbidden();

        $this->actAs($this->admin);
        $this->getJson('/api/v1/approvals/pending')->assertOk()->assertJsonFragment(['link' => '/bank/reserve-to-investment', 'amount' => 200000]);
        $this->actAs($this->secondApprover($this->admin));
        $this->postJson("/api/v1/bank/transfers/{$id}/approve")->assertOk();
        $this->actAs($this->admin);

        $this->assertSame(200000.0, $this->ledger->balance($companyId, Account::InvestmentReserve));
        $this->assertSame(150000.0, $this->ledger->balance($companyId, Account::Reserve, $this->admin->branch_id), 'taken in proportion to each branch reserve');
        $this->assertSame(50000.0, $this->ledger->balance($companyId, Account::Reserve, $this->otherBranch));

        $this->getJson('/api/v1/bank/reserve-to-investment')->assertOk()
            ->assertJsonPath('hq_reserve_balance', 200000)
            ->assertJsonPath('investment_reserve_balance', 200000)
            ->assertJsonPath('total', 200000);
        $this->getJson('/api/v1/hq/balances')->assertOk()->assertJsonPath('data.3.balance', 200000);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.account_balances.Reserve A/C', 200000);

        $this->postJson("/api/v1/bank/transfers/{$id}/reverse", ['reason' => 'Sent too early'])->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->assertSame(0.0, $this->ledger->balance($companyId, Account::InvestmentReserve));
        $this->assertSame(300000.0, $this->ledger->balance($companyId, Account::Reserve, $this->admin->branch_id));
    }

    public function test_more_than_the_hq_reserve_cannot_be_requested_or_approved(): void
    {
        $this->actAs($this->finance);
        $this->postJson('/api/v1/bank/reserve-to-investment', ['amount' => 400001])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $id = $this->postJson('/api/v1/bank/reserve-to-investment', ['amount' => 400000])->assertCreated()->json('data.id');
        $this->actAs($this->admin);
        $this->ledger->transfer($this->admin->company_id, ['account' => Account::Reserve, 'branch' => $this->otherBranch->id], ['account' => Account::InterestReserve], 1000, 'RESERVE ADJUSTMENT');

        $this->asApprover($this->admin, fn () => $this->postJson("/api/v1/bank/transfers/{$id}/approve")->assertUnprocessable()->assertJsonValidationErrors('amount'));
        $this->assertSame('pending', BankTransfer::findOrFail($id)->status);
    }

    public function test_only_super_admin_admin_or_a_shareholder_approve_a_reserve_transfer(): void
    {
        $companyId = $this->admin->company_id;
        $this->actAs($this->finance);
        $first = $this->postJson('/api/v1/bank/reserve-to-investment', ['amount' => 100000])->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v1/bank/reserve-to-investment', ['amount' => 50000])->assertCreated()->json('data.id');

        // Finance owns this leg: it sends the HQ reserve to the Investment, but decides nothing — and never touches the
        // owners' second leg (Investment → OPERATION PRINCIPAL).
        $this->getJson('/api/v1/bank/reserve-to-investment')->assertOk();
        $this->postJson('/api/v1/bank/reserve-to-investment', ['amount' => 1000])->assertCreated();
        $this->getJson('/api/v1/bank/reserve-to-principal')->assertForbidden();
        $this->postJson('/api/v1/bank/reserve-to-principal', ['amount' => 1000])->assertForbidden();
        $this->postJson("/api/v1/bank/transfers/{$first}/approve")->assertForbidden();
        $this->postJson("/api/v1/bank/transfers/{$first}/reject", ['reason' => 'Not now'])->assertForbidden();
        $this->assertSame(0.0, $this->ledger->balance($companyId, Account::InvestmentReserve));

        $holder = ShareHolder::create(['company_id' => $companyId, 'first_name' => 'ASHA', 'last_name' => 'HOLDER', 'mobile' => '0768999301', 'email' => 'asha@example.com', 'gender' => 'female', 'date_of_birth' => '1990-01-01']);
        $account = app(ShareholderAccounts::class)->provision($holder, $this->admin)['account'];
        $account->forceFill(['must_change_password' => false])->save();

        $this->actAs($account);
        $this->postJson("/api/v1/bank/transfers/{$first}/approve")->assertForbidden();
        $this->getJson('/api/v1/portal/shareholder/reserve-transfers')->assertOk()
            ->assertJsonPath('hq_reserve_balance', 400000)
            ->assertJsonPath('data.0.can_approve', true)
            ->assertJsonPath('data.0.can_reject', true);
        $this->postJson("/api/v1/portal/shareholder/reserve-transfers/{$first}/approve")->assertOk();
        $this->postJson("/api/v1/portal/shareholder/reserve-transfers/{$second}/reject", ['reason' => 'Wait for month end'])->assertOk();

        $this->assertSame(100000.0, $this->ledger->balance($companyId, Account::InvestmentReserve));
        $this->assertSame('approved', BankTransfer::findOrFail($first)->status);
        $this->assertSame((int) $account->id, (int) BankTransfer::findOrFail($first)->approved_by);
        $this->assertSame('rejected', BankTransfer::findOrFail($second)->status);

        $third = BankTransfer::create(['company_id' => $companyId, 'type' => 'company_to_bank', 'employee_id' => $this->admin->id, 'amount' => 10, 'status' => 'pending', 'transfer_date' => today()]);
        $this->postJson("/api/v1/portal/shareholder/reserve-transfers/{$third->id}/approve")->assertNotFound();

        $this->actAs($this->finance);
        $fourth = $this->postJson('/api/v1/bank/reserve-to-investment', ['amount' => 20000])->assertCreated()->json('data.id');
        $this->actAs($this->secondApprover($this->admin, 'admin'));
        $this->postJson("/api/v1/bank/transfers/{$fourth}/approve")->assertOk();
        $this->assertSame(120000.0, $this->ledger->balance($companyId, Account::InvestmentReserve));
    }

    private function actAs(Employee $employee): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($employee->fresh());
    }

    public function test_the_whole_hq_reserve_can_be_sent_with_uneven_cents(): void
    {
        $this->ledger->openingBalance($this->admin->company_id, Account::Reserve, 0.03, branch: $this->otherBranch);

        $this->actAs($this->finance);
        $id = $this->postJson('/api/v1/bank/reserve-to-investment', ['amount' => 400000.03])->assertCreated()->json('data.id');
        $this->actAs($this->admin);
        $this->approveAsSecondUser($this->admin, "/api/v1/bank/transfers/{$id}/approve");

        $this->assertSame(400000.03, $this->ledger->balance($this->admin->company_id, Account::InvestmentReserve));
        $this->assertSame(0.0, $this->ledger->balance($this->admin->company_id, Account::Reserve, allBranches: true));
    }
}

<?php

namespace Tests\Feature\Api\Bank;

use App\Enums\Account;
use App\Models\BankTransfer;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

/**
 * The second leg of the reserve chain. Finance sends HQ reserve to the Investment RESERVE A/C; the owners then send it on to
 * the OPERATION PRINCIPAL. The owners can only send what Finance has already sent them — reserve still held at HQ is out of
 * reach — and a second authorised user (Super Admin, Admin or a shareholder) must approve.
 */
class ReserveToPrincipalApiTest extends TestCase
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
        $this->ledger->openingBalance($this->admin->company_id, Account::Reserve, 500000, branch: $this->admin->branch_id);
    }

    public function test_only_reserve_finance_has_already_sent_can_go_to_the_operation_principal(): void
    {
        $companyId = $this->admin->company_id;

        // Nothing has been sent to the Investment yet, so nothing can leave it — even though HQ holds 500,000.
        $this->postJson('/api/v1/bank/reserve-to-principal', ['amount' => 1])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->sendToInvestment(200000);
        $this->assertSame(200000.0, $this->ledger->balance($companyId, Account::InvestmentReserve));
        $this->assertSame(300000.0, $this->ledger->balance($companyId, Account::Reserve, allBranches: true), 'the rest stays in the HQ reserve');

        $this->postJson('/api/v1/bank/reserve-to-principal', ['amount' => 200001])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertSame(0.0, $this->ledger->balance($companyId, Account::Principal), 'no lending cash yet');
        $id = $this->postJson('/api/v1/bank/reserve-to-principal', ['amount' => 150000])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->assertSame(200000.0, $this->ledger->balance($companyId, Account::InvestmentReserve), 'a pending transfer moves nothing');
        $this->getJson('/api/v1/approvals/pending')->assertOk()->assertJsonFragment(['link' => '/bank/reserve-to-principal', 'amount' => 150000]);

        $this->approveAsSecondUser($this->admin, "/api/v1/bank/transfers/{$id}/approve");

        $this->assertSame(50000.0, $this->ledger->balance($companyId, Account::InvestmentReserve));
        $this->assertSame(150000.0, $this->ledger->balance($companyId, Account::Principal));
        $this->assertSame(300000.0, $this->ledger->balance($companyId, Account::Reserve, allBranches: true), 'the HQ reserve is untouched by this leg');

        $this->getJson('/api/v1/bank/reserve-to-principal')->assertOk()
            ->assertJsonPath('investment_reserve_balance', 50000)
            ->assertJsonPath('operation_principal_balance', 150000)
            ->assertJsonPath('total', 150000);

        $this->postJson("/api/v1/bank/transfers/{$id}/reverse", ['reason' => 'Sent by mistake'])->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->assertSame(200000.0, $this->ledger->balance($companyId, Account::InvestmentReserve));
        $this->assertSame(0.0, $this->ledger->balance($companyId, Account::Principal));
    }

    public function test_an_approval_is_refused_when_the_investment_reserve_no_longer_covers_it(): void
    {
        $this->sendToInvestment(100000);

        $first = $this->postJson('/api/v1/bank/reserve-to-principal', ['amount' => 100000])->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v1/bank/reserve-to-principal', ['amount' => 100000])->assertCreated()->json('data.id');

        $this->approveAsSecondUser($this->admin, "/api/v1/bank/transfers/{$first}/approve");
        $this->asApprover($this->admin, fn () => $this->postJson("/api/v1/bank/transfers/{$second}/approve")->assertUnprocessable()->assertJsonValidationErrors('amount'));

        $this->assertSame('pending', BankTransfer::findOrFail($second)->status);
        $this->assertSame(0.0, $this->ledger->balance($this->admin->company_id, Account::InvestmentReserve));
    }

    public function test_a_branch_manager_can_neither_see_nor_request_the_owners_leg(): void
    {
        $this->sendToInvestment(100000);
        $id = $this->postJson('/api/v1/bank/reserve-to-principal', ['amount' => 10000])->assertCreated()->json('data.id');

        $manager = Employee::factory()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => Branch::factory()->create(['company_id' => $this->admin->company_id])->id,
            'role_id' => $this->admin->company->roles()->where('key', 'branch_manager')->value('id'),
        ]);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($manager->fresh());

        $this->getJson('/api/v1/bank/reserve-to-principal')->assertForbidden();
        $this->postJson('/api/v1/bank/reserve-to-principal', ['amount' => 1000])->assertForbidden();
        $this->postJson("/api/v1/bank/transfers/{$id}/approve")->assertForbidden();
        $this->assertSame('pending', BankTransfer::findOrFail($id)->status);
    }

    /**
     * Finance's leg, run to completion, so the Investment RESERVE A/C actually holds something to send on. Raised as
     * Finance because `funds.transfer` is Finance's own permission: the owners approve leg 1, they never raise it.
     */
    private function sendToInvestment(float $amount): void
    {
        $finance = $this->secondApprover($this->admin, 'finance');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($finance->fresh());
        $id = $this->postJson('/api/v1/bank/reserve-to-investment', ['amount' => $amount])->assertCreated()->json('data.id');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->admin->fresh());
        $this->approveAsSecondUser($this->admin, "/api/v1/bank/transfers/{$id}/approve");
    }
}

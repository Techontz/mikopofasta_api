<?php

namespace Tests\Feature\Api\Bank;

use App\Enums\Account;
use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

class BankTransferReversalApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    private Ledger $ledger;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->ledger = app(Ledger::class);
        $this->bank = BankAccount::create(['company_id' => $this->admin->company_id, 'name' => 'NMB']);
    }

    public function test_bank_to_company_reversal_is_blocked_once_the_company_account_spent_the_money(): void
    {
        $this->ledger->openingBalance($this->admin->company_id, Account::Bank, 100000, bankAccount: $this->bank);
        $this->approve($this->postJson('/api/v1/bank/company-transfers', ['direction' => 'bank_to_company', 'bank_account_id' => $this->bank->id, 'amount' => 30000])->assertCreated()->json('data.id'));
        $transfer = BankTransfer::firstOrFail();

        $this->ledger->transfer($this->admin->company_id, ['account' => Account::Company], ['account' => Account::HqSalaryAdvance], 20000, 'Spent');
        $entries = JournalEntry::count();

        $this->postJson("/api/v1/bank/transfers/{$transfer->id}/reverse", ['reason' => 'Mistake'])->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'The receiving account ('.Account::Company->label().') no longer holds TZS 30,000 (balance TZS 10,000); the money has already been used.');
        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame('approved', $transfer->fresh()->status);

        $this->ledger->transfer($this->admin->company_id, ['account' => Account::HqSalaryAdvance], ['account' => Account::Company], 20000, 'Returned');
        $this->postJson("/api/v1/bank/transfers/{$transfer->id}/reverse", ['reason' => 'Mistake'])->assertOk();
        $this->assertSame(100000.0, $this->bank->balance());
        $this->assertSame(0.0, $this->ledger->balance($this->admin->company_id, Account::Company));
    }

    public function test_company_cash_bank_reversal(): void
    {
        $this->ledger->openingBalance($this->admin->company_id, Account::Company, 70000);

        $pending = $this->postJson('/api/v1/bank/company-transfers', ['direction' => 'company_to_bank', 'bank_account_id' => $this->bank->id, 'amount' => 25000])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/bank/transfers/{$pending}/reverse", ['reason' => 'Pending'])->assertUnprocessable();
        $this->approve($pending);

        $deposit = BankTransfer::where('type', 'company_to_bank')->firstOrFail();
        $this->assertNotNull($deposit->journal_entry_id);
        $this->assertSame(25000.0, $this->bank->balance());
        $this->getJson('/api/v1/bank/company-transfers')->assertOk()->assertJsonPath('data.0.can_reverse', true);

        $this->postJson("/api/v1/bank/transfers/{$deposit->id}/reverse", ['reason' => 'Slip rejected'])->assertOk();
        $this->assertSame(70000.0, $this->ledger->balance($this->admin->company_id, Account::Company));
        $this->assertSame(0.0, $this->bank->balance());
        $this->getJson('/api/v1/bank/company-transfers')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'reversed')->assertJsonPath('total', 0)->assertJsonPath('total_reversed', 25000);
    }

    public function test_rollback_permissions_and_company_isolation(): void
    {
        $this->ledger->openingBalance($this->admin->company_id, Account::Bank, 10000, bankAccount: $this->bank);
        $this->approve($this->postJson('/api/v1/bank/company-transfers', ['direction' => 'bank_to_company', 'bank_account_id' => $this->bank->id, 'amount' => 5000])->assertCreated()->json('data.id'));
        $transfer = BankTransfer::firstOrFail();

        $foreignCompany = Company::factory()->create();
        $foreign = BankTransfer::create(['company_id' => $foreignCompany->id, 'type' => 'bank_to_company', 'amount' => 1, 'status' => 'approved', 'transfer_date' => today()]);
        $this->postJson("/api/v1/bank/transfers/{$foreign->id}/reverse", ['reason' => 'Mistake'])->assertNotFound();

        $roles = $this->admin->company->roles()->pluck('id', 'key');
        $admin = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'role_id' => $roles['admin']]);
        $this->actingAs($admin)->postJson("/api/v1/bank/transfers/{$transfer->id}/reverse", ['reason' => 'Mistake'])->assertForbidden();
        $this->actingAs($admin)->getJson('/api/v1/bank/company-transfers')->assertOk()->assertJsonPath('data.0.can_reverse', false);

        $this->actingAs($this->admin);
        $entries = JournalEntry::count();
        $real = $this->ledger;
        $this->partialMock(Ledger::class, function ($mock) use ($real): void {
            $mock->shouldReceive('reverse')->andReturnUsing(function (JournalEntry $entry, string $reason) use ($real): never {
                $real->reverse($entry, $reason);

                throw new RuntimeException('Ledger failure');
            });
        });

        $this->withoutExceptionHandling();
        try {
            $this->postJson("/api/v1/bank/transfers/{$transfer->id}/reverse", ['reason' => 'Mistake']);
            $this->fail('The posting failure must surface.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Ledger failure', $exception->getMessage());
        }

        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame('approved', $transfer->fresh()->status);
        $this->assertSame(5000.0, $real->balance($this->admin->company_id, Account::Company));
    }

    /**
     * Rule 6: movements requested by the signed-in admin are approved (posted) by a second authorised user.
     */
    private function approve(int $id): void
    {
        $this->approveAsSecondUser($this->admin, "/api/v1/bank/transfers/{$id}/approve");
    }
}

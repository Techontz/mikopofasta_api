<?php

namespace Tests\Feature\Api\Hq;

use App\Enums\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\HqTransaction;
use App\Models\JournalEntry;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

class HqTransactionReversalApiTest extends TestCase
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

    public function test_reversal_restores_both_hq_accounts_and_the_charge(): void
    {
        $transaction = $this->approved(60000, 1000);
        $this->assertNotNull($transaction->journal_entry_id);
        $this->assertSame(39000.0, $this->ledger->balance($this->admin->company_id, Account::HqInterest));

        $this->getJson('/api/v1/hq/transactions?status=approved')->assertOk()->assertJsonPath('data.0.can_reverse', true);
        $this->postJson("/api/v1/hq/transactions/{$transaction->id}/reverse", ['reason' => 'Wrong account'])->assertOk()->assertJsonPath('data.status', 'reversed');

        $this->assertSame(100000.0, $this->ledger->balance($this->admin->company_id, Account::HqInterest));
        $this->assertSame(0.0, $this->ledger->balance($this->admin->company_id, Account::HqDisbursement));
        $this->assertSame(0.0, $this->ledger->balance($this->admin->company_id, Account::BankCharges));

        $transaction->refresh();
        $this->assertSame($this->admin->id, $transaction->reversed_by);
        $this->assertSame($transaction->journal_entry_id, JournalEntry::findOrFail($transaction->reversal_journal_entry_id)->reversal_of_id);
        $this->assertTrue(AuditLog::where('action', 'HqTransaction.reversed')->where('auditable_id', $transaction->id)->exists());

        $this->getJson('/api/v1/hq/transactions?status=approved')->assertOk()
            ->assertJsonPath('data.0.status', 'reversed')
            ->assertJsonPath('data.0.reversal_reason', 'Wrong account')
            ->assertJsonPath('total', 0)
            ->assertJsonPath('total_charge', 0)
            ->assertJsonPath('total_reversed', 60000);

        $this->postJson("/api/v1/hq/transactions/{$transaction->id}/reverse", ['reason' => 'Again'])->assertUnprocessable();
        $this->deleteJson("/api/v1/hq/transactions/{$transaction->id}")->assertUnprocessable();
    }

    public function test_reversal_is_blocked_when_the_receiving_account_was_spent_and_nothing_is_posted(): void
    {
        $transaction = $this->approved(60000);
        $this->ledger->transfer($this->admin->company_id, ['account' => Account::HqDisbursement], ['account' => Account::Company], 60000, 'Spent');
        $entries = JournalEntry::count();

        $this->postJson("/api/v1/hq/transactions/{$transaction->id}/reverse", ['reason' => 'Mistake'])->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'The receiving account (DISBURSEMENT ACCOUNT) no longer holds TZS 60,000 (balance TZS 0); the money has already been used.');

        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame('approved', $transaction->fresh()->status);
    }

    public function test_concurrent_approvals_post_once_and_pending_cannot_be_reversed(): void
    {
        $this->ledger->openingBalance($this->admin->company_id, Account::HqInterest, 100000);
        $transaction = HqTransaction::create(['company_id' => $this->admin->company_id, 'from_account' => Account::HqInterest->value, 'to_account' => Account::HqSaving->value, 'amount' => 5000, 'status' => 'pending']);

        $this->postJson("/api/v1/hq/transactions/{$transaction->id}/reverse", ['reason' => 'Not yet'])->assertUnprocessable();
        $this->postJson("/api/v1/hq/transactions/{$transaction->id}/approve")->assertOk();
        $entries = JournalEntry::count();
        $this->postJson("/api/v1/hq/transactions/{$transaction->id}/approve")->assertUnprocessable();

        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame(95000.0, $this->ledger->balance($this->admin->company_id, Account::HqInterest));
    }

    public function test_rollback_permissions_and_company_isolation(): void
    {
        $transaction = $this->approved(1000);

        $foreign = HqTransaction::create(['company_id' => Company::factory()->create()->id, 'from_account' => Account::HqInterest->value, 'to_account' => Account::HqSaving->value, 'amount' => 1, 'status' => 'approved']);
        $this->postJson("/api/v1/hq/transactions/{$foreign->id}/reverse", ['reason' => 'Mistake'])->assertNotFound();

        $roles = $this->admin->company->roles()->pluck('id', 'key');
        $admin = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'role_id' => $roles['admin']]);
        $this->actingAs($admin)->postJson("/api/v1/hq/transactions/{$transaction->id}/reverse", ['reason' => 'Mistake'])->assertForbidden();
        $finance = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'role_id' => $roles['finance']]);
        $this->actingAs($finance)->getJson('/api/v1/hq/transactions?status=approved')->assertOk()->assertJsonPath('data.0.can_reverse', true);

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
            $this->postJson("/api/v1/hq/transactions/{$transaction->id}/reverse", ['reason' => 'Mistake']);
            $this->fail('The posting failure must surface.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Ledger failure', $exception->getMessage());
        }

        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame('approved', $transaction->fresh()->status);
    }

    private function approved(float $amount, float $charge = 0): HqTransaction
    {
        $this->ledger->openingBalance($this->admin->company_id, Account::HqInterest, 100000);
        $transaction = HqTransaction::create([
            'company_id' => $this->admin->company_id, 'from_account' => Account::HqInterest->value, 'to_account' => Account::HqDisbursement->value,
            'amount' => $amount, 'charge' => $charge, 'status' => 'pending',
        ]);
        // Rule 6: posted by a second authorised user, so the signed-in admin may reverse it.
        $this->approveAsSecondUser($this->admin, "/api/v1/hq/transactions/{$transaction->id}/approve");

        return $transaction->fresh();
    }
}

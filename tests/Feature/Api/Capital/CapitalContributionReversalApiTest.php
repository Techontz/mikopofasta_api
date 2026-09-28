<?php

namespace Tests\Feature\Api\Capital;

use App\Enums\Account;
use App\Enums\ShareTransactionType;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Capital;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\ShareHolder;
use App\Models\ShareTransaction;
use App\Services\Ledger;
use App\Services\ShareholderOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

/**
 * Cash / bank capital contribution reversal (Fund Flow Specification §21, §26 Option A, §29): Dr CAPITAL ACCOUNT /
 * Cr the receiving account, original kept and marked reversed, blocked by dependants.
 */
class CapitalContributionReversalApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    private Ledger $ledger;

    private ShareHolder $holder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->ledger = app(Ledger::class);
        $this->holder = ShareHolder::create(['company_id' => $this->admin->company_id, 'name' => 'ALPHA INVESTOR', 'mobile' => '0777', 'email' => 'alpha@example.com', 'date_of_birth' => '1990-01-01']);
    }

    public function test_cash_contribution_reversal_restores_company_cash_and_capital_and_keeps_the_original(): void
    {
        $id = $this->contribute(500000)->json('data.id');
        $this->contribute(200000);
        $capital = Capital::findOrFail($id);

        $this->getJson('/api/v1/capital/capitals')->assertOk()
            ->assertJsonPath('data.share_holders.0.capitals.0.can_reverse', true)
            ->assertJsonPath('data.share_holders.0.capitals.0.reverse_blocked_reason', null)
            ->assertJsonPath('data.share_holder_capital', 700000);

        $this->postJson("/api/v1/capital/capitals/{$id}/reverse", ['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/capital/capitals/{$id}/reverse", ['reason' => 'Recorded twice'])->assertOk()
            ->assertJsonPath('message', 'Capital Contribution Reversed successfully')
            ->assertJsonPath('data.reversed', true)
            ->assertJsonPath('data.status', 'reversed')
            ->assertJsonPath('data.can_reverse', false);

        $this->assertSame(200000.0, $this->ledger->balance($this->admin->company_id, Account::Company));
        $this->assertSame(200000.0, $this->ledger->balance($this->admin->company_id, Account::Capital));

        $capital->refresh();
        $this->assertTrue($capital->isReversed());
        $this->assertSame($this->admin->id, $capital->reversed_by);
        $this->assertSame('Recorded twice', $capital->reversal_reason);
        $reversal = JournalEntry::with('lines.account')->findOrFail($capital->reversal_journal_entry_id);
        $this->assertSame($capital->journal_entry_id, $reversal->reversal_of_id);
        $this->assertSame(500000.0, (float) $reversal->lines->first(fn ($line): bool => $line->account->key === Account::Capital)->debit);
        $this->assertSame(500000.0, (float) $reversal->lines->first(fn ($line): bool => $line->account->key === Account::Company)->credit);
        $this->assertTrue(AuditLog::where('action', 'Capital.reversed')->where('auditable_id', $capital->id)->exists());

        $ownership = app(ShareholderOwnership::class);
        $this->assertSame(200000.0, $ownership->totalContributed($this->admin->company_id));
        $this->assertSame(200000.0, $ownership->forShareHolder($this->holder)['total_contributed']);

        $this->getJson('/api/v1/capital/capitals')->assertOk()
            ->assertJsonPath('data.share_holder_capital', 200000)
            ->assertJsonPath('data.contribution_breakdown.cash', 200000)
            ->assertJsonPath('data.share_holders.0.total_contributed', 200000)
            ->assertJsonPath('data.share_holders.0.capitals.0.reversed', true)
            ->assertJsonPath('data.share_holders.0.capitals.0.reversed_by', $this->admin->full_name)
            ->assertJsonPath('data.share_holders.0.capitals.0.reversal_reference', $reversal->reference)
            ->assertJsonPath('data.share_holders.0.capitals.0.can_reverse', false)
            ->assertJsonPath('data.share_holders.0.capitals.0.reverse_blocked_reason', null);
        $this->getJson("/api/v1/capital/share-holders/{$this->holder->id}/contributions")->assertOk()->assertJsonPath('data.total_contributed', 200000);

        $entries = JournalEntry::count();
        $this->postJson("/api/v1/capital/capitals/{$id}/reverse", ['reason' => 'Again'])->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'This capital contribution has already been reversed.');
        $this->assertSame($entries, JournalEntry::count());
    }

    public function test_bank_contribution_reversal_credits_that_bank_account(): void
    {
        $bank = BankAccount::create(['company_id' => $this->admin->company_id, 'name' => 'NMB']);
        $id = $this->contribute(300000, ['pay_method' => 'BANK', 'bank_account_id' => $bank->id])->json('data.id');

        $this->postJson("/api/v1/capital/capitals/{$id}/reverse", ['reason' => 'Wrong bank'])->assertOk();

        $this->assertSame(0.0, $this->ledger->balance($this->admin->company_id, Account::Bank, bankAccount: $bank));
        $this->assertSame(0.0, $this->ledger->balance($this->admin->company_id, Account::Capital));
    }

    public function test_reversal_is_blocked_while_shares_issued_against_the_contribution_are_active(): void
    {
        $id = $this->contribute(100000)->json('data.id');
        $shares = ShareTransaction::create([
            'company_id' => $this->admin->company_id, 'reference' => 'SHR-TEST-1', 'type' => ShareTransactionType::Issuance,
            'to_share_holder_id' => $this->holder->id, 'shares' => 10, 'share_value' => 10000, 'transacted_at' => now(),
            'status' => ShareTransaction::COMPLETED, 'capital_id' => $id, 'payment_treatment' => ShareTransaction::TREATMENT_LINKED,
        ]);
        $message = 'Shares were issued against this contribution (SHR-TEST-1); reverse that share transaction first.';

        $this->getJson('/api/v1/capital/capitals')->assertOk()
            ->assertJsonPath('data.share_holders.0.capitals.0.can_reverse', false)
            ->assertJsonPath('data.share_holders.0.capitals.0.reverse_blocked_reason', $message);
        $this->postJson("/api/v1/capital/capitals/{$id}/reverse", ['reason' => 'Mistake'])->assertUnprocessable()->assertJsonPath('errors.reason.0', $message);
        $this->assertFalse(Capital::findOrFail($id)->isReversed());
        $this->assertSame(100000.0, $this->ledger->balance($this->admin->company_id, Account::Capital));

        $shares->update(['status' => ShareTransaction::REVERSED]);
        $this->postJson("/api/v1/capital/capitals/{$id}/reverse", ['reason' => 'Mistake'])->assertOk();
    }

    public function test_reversal_is_blocked_when_the_receiving_account_already_used_the_money(): void
    {
        $id = $this->contribute(1000)->json('data.id');
        $float = $this->postJson('/api/v1/capital/floats', ['amount' => 600, 'from_account' => Account::Company->value])->assertCreated()->json('data.id');
        $this->approveAsSecondUser($this->admin, "/api/v1/capital/floats/{$float}/approve");
        $entries = JournalEntry::count();
        $message = 'The receiving account (COMPANY ACCOUNT) no longer holds TZS 1,000 (balance TZS 400); the money has already been used.';

        $this->getJson('/api/v1/capital/capitals')->assertOk()->assertJsonPath('data.share_holders.0.capitals.0.reverse_blocked_reason', $message);
        $this->postJson("/api/v1/capital/capitals/{$id}/reverse", ['reason' => 'Mistake'])->assertUnprocessable()->assertJsonPath('errors.reason.0', $message);

        $this->assertSame($entries, JournalEntry::count());
        $this->assertFalse(Capital::findOrFail($id)->isReversed());
        $this->assertSame(400.0, $this->ledger->balance($this->admin->company_id, Account::Company));
    }

    public function test_asset_contributions_are_reversed_from_the_asset_register(): void
    {
        $asset = Capital::create([
            'company_id' => $this->admin->company_id, 'share_holder_id' => $this->holder->id, 'amount' => 900000, 'pay_method' => 'ASSET',
            'receiving_account' => Account::fixedAssets()[0]->value, 'recorded_by' => $this->admin->id, 'contributed_at' => now(),
        ]);

        $this->postJson("/api/v1/capital/capitals/{$asset->id}/reverse", ['reason' => 'Mistake'])->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'Reverse asset contributions from the asset register.');
        $this->assertFalse($asset->fresh()->isReversed());
    }

    public function test_permissions_and_company_isolation(): void
    {
        $id = $this->contribute(1000)->json('data.id');
        $roles = $this->admin->company->roles()->pluck('id', 'key');

        $finance = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'role_id' => $roles['finance']]);
        $this->actingAs($finance)->postJson("/api/v1/capital/capitals/{$id}/reverse", ['reason' => 'Mistake'])->assertForbidden();
        $admin = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'role_id' => $roles['admin']]);
        $this->actingAs($admin)->postJson("/api/v1/capital/capitals/{$id}/reverse", ['reason' => 'Mistake'])->assertForbidden();

        $otherCompany = Company::factory()->create();
        $foreignHolder = ShareHolder::create(['company_id' => $otherCompany->id, 'name' => 'X', 'mobile' => '1', 'email' => 'x@example.com', 'date_of_birth' => '1990-01-01']);
        $foreign = Capital::create(['company_id' => $otherCompany->id, 'share_holder_id' => $foreignHolder->id, 'amount' => 5, 'pay_method' => 'CASH', 'receiving_account' => Account::Company->value, 'contributed_at' => now()]);
        $this->actingAs($this->admin)->postJson("/api/v1/capital/capitals/{$foreign->id}/reverse", ['reason' => 'Mistake'])->assertNotFound();

        $this->assertFalse(Capital::findOrFail($id)->isReversed());
        $this->assertFalse($foreign->fresh()->isReversed());
    }

    public function test_reversal_rolls_back_when_posting_fails(): void
    {
        $id = $this->contribute(1000)->json('data.id');
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
            $this->postJson("/api/v1/capital/capitals/{$id}/reverse", ['reason' => 'Mistake']);
            $this->fail('The posting failure must surface.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Ledger failure', $exception->getMessage());
        }

        $this->assertSame($entries, JournalEntry::count());
        $this->assertFalse(Capital::findOrFail($id)->isReversed());
        $this->assertFalse(AuditLog::where('action', 'Capital.reversed')->exists());
        $this->assertSame(1000.0, $real->balance($this->admin->company_id, Account::Company));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function contribute(float $amount, array $extra = []): TestResponse
    {
        $id = $this->postJson('/api/v1/capital/capitals', $extra + ['share_id' => $this->holder->id, 'amount' => $amount, 'pay_method' => 'CASH'])->assertCreated()->json('data.id');

        return $this->approveAsSecondUser($this->admin, "/api/v1/capital/capitals/{$id}/approve");
    }
}

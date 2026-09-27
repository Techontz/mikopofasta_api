<?php

namespace Tests\Feature\Api\Shares;

use App\Enums\Account;
use App\Enums\ShareTransactionType;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\BranchPeriodResult;
use App\Models\Capital;
use App\Models\DividendAllocation;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\ShareHolder;
use App\Models\SharePosition;
use App\Models\ShareStructure;
use App\Models\ShareTransaction;
use App\Models\ShareValuation;
use App\Services\Ledger;
use App\Services\ShareholderOwnership;
use App\Services\Shares\ShareRegister;
use App\Services\Shares\ShareTransfers;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

/**
 * Shares Management: shares are ownership units, share value is the value per unit, ownership % = shares ÷ total issued
 * shares × 100, holding value = shares × share value. Issuance grows total issued shares, a transfer never changes it and
 * a share value change moves holding values only. Valuations are memorandum records; paid issuance posts
 * Dr Company Cash/Bank / Cr Share Capital through the capital contribution service.
 */
class ShareRegisterTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    private ShareHolder $a;

    private ShareHolder $b;

    private ShareHolder $c;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-13 10:00:00'));
        $this->admin = $this->signInAdmin();
        $this->a = $this->holder('ALPHA');
        $this->b = $this->holder('BETA');
        $this->c = $this->holder('GAMMA');
    }

    public function test_scenario_1_initial_structure_derives_the_share_value_from_the_capital_basis(): void
    {
        $this->establish()->assertCreated()->assertJsonPath('message', 'Share Structure Created successfully');

        $structure = ShareStructure::sole();
        $this->assertSame([50000000.0, 1000, 50000.0], [(float) $structure->initial_capital_basis, $structure->initial_shares, (float) $structure->initial_share_value]);

        $this->getJson('/api/v1/shares/overview')->assertOk()
            ->assertJsonPath('data.has_structure', true)
            ->assertJsonPath('data.total_issued_shares', 1000)
            ->assertJsonPath('data.current_share_value', 50000)
            ->assertJsonPath('data.total_valuation', 50000000)
            ->assertJsonPath('data.authorised_shares', 2000)
            ->assertJsonPath('data.available_shares', 1000)
            ->assertJsonPath('data.register_consistent', true);

        $initial = ShareValuation::sole();
        $this->assertSame(['initial', null, 50000.0, 1000, 50000000.0], [$initial->kind, $initial->previous_value, (float) $initial->new_value, $initial->total_shares, (float) $initial->new_total_valuation]);
    }

    public function test_scenario_2_initial_allocation_to_founders_gives_fifty_fifty_and_twenty_five_million_each(): void
    {
        $this->establish();

        $this->assertRegister([$this->a->id => [500, 50, 25000000], $this->b->id => [500, 50, 25000000], $this->c->id => [0, 0, 0]], 1000, 50000);
        $this->assertSame(2, ShareTransaction::where('type', ShareTransactionType::InitialAllocation)->count());
    }

    public function test_scenario_3_share_value_change_updates_holding_values_but_never_share_counts(): void
    {
        $this->establish();
        $journals = JournalEntry::count();
        $cash = $this->ledger()->balance($this->admin->company_id, Account::Company);

        $this->revalue(100000, '2026-09-13')->assertCreated();

        $this->assertRegister([$this->a->id => [500, 50, 50000000], $this->b->id => [500, 50, 50000000]], 1000, 100000);
        $this->getJson('/api/v1/shares/overview')->assertJsonPath('data.total_valuation', 100000000);

        $valuation = ShareValuation::where('kind', 'revaluation')->sole();
        $this->assertSame([50000.0, 100000.0, 1000, 50000000.0, 100000000.0], [(float) $valuation->previous_value, (float) $valuation->new_value, $valuation->total_shares, (float) $valuation->previous_total_valuation, (float) $valuation->new_total_valuation]);
        $this->assertSame($journals, JournalEntry::count(), 'a valuation posts no journal entry');
        $this->assertSame($cash, $this->ledger()->balance($this->admin->company_id, Account::Company), 'share value is not cash');
        $this->assertSame(2, ShareTransaction::count(), 'a valuation creates no share movement');
    }

    public function test_scenario_4_issuance_increases_total_issued_shares_and_dilutes_ownership(): void
    {
        $this->establish();
        $this->revalue(100000, '2026-09-13');

        $this->issuePaid($this->c, 250, ['pay_method' => 'CASH'])
            ->assertJsonPath('data.shares', 250)
            ->assertJsonPath('data.price_per_share', 100000)
            ->assertJsonPath('data.amount', 25000000)
            ->assertJsonPath('data.status', 'approved');

        $this->assertRegister([$this->a->id => [500, 40, 50000000], $this->b->id => [500, 40, 50000000], $this->c->id => [250, 20, 25000000]], 1250, 100000);
    }

    public function test_scenario_5_transfer_moves_existing_shares_and_never_changes_the_total(): void
    {
        $this->establish();
        $this->revalue(100000, '2026-09-13');
        $before = app(ShareRegister::class)->issuedShares($this->admin->company_id);
        $journals = JournalEntry::count();

        $this->transfer($this->a, $this->c, 100, ['consideration_per_share' => 90000])->assertCreated()
            ->assertJsonPath('data.issued_change', 0)
            ->assertJsonPath('data.total_amount', 9000000);

        $this->assertSame($before, app(ShareRegister::class)->issuedShares($this->admin->company_id), 'before total == after total');
        $this->assertRegister([$this->a->id => [400, 40, 40000000], $this->b->id => [500, 50, 50000000], $this->c->id => [100, 10, 10000000]], 1000, 100000);
        $this->assertSame($journals, JournalEntry::count(), 'a transfer between shareholders posts no company ledger entry');
    }

    public function test_scenario_6_value_change_after_a_transfer_revalues_every_holding(): void
    {
        $this->establish();
        $this->revalue(100000, '2026-09-13');
        $this->transfer($this->a, $this->c, 100);

        $this->revalue(200000, '2026-09-13')->assertCreated();

        $this->assertRegister([$this->a->id => [400, 40, 80000000], $this->b->id => [500, 50, 100000000], $this->c->id => [100, 10, 20000000]], 1000, 200000);
        $this->getJson('/api/v1/shares/overview')->assertJsonPath('data.total_valuation', 200000000);
    }

    public function test_historical_holding_value_uses_the_shares_and_value_effective_on_each_date(): void
    {
        $this->establish(['established_on' => '2026-01-01']);
        $this->revalue(75000, '2026-06-01')->assertCreated();
        $this->revalue(100000, '2026-09-01')->assertCreated();

        $register = app(ShareRegister::class);
        $this->assertSame(25000000.0, $register->holdingAt($this->a, CarbonImmutable::parse('2026-01-01'))['holding_value']);
        $this->assertSame(37500000.0, $register->holdingAt($this->a, CarbonImmutable::parse('2026-06-01'))['holding_value']);
        $this->assertSame(50000000.0, $register->holdingAt($this->a, CarbonImmutable::parse('2026-09-01'))['holding_value']);
        $this->assertSame(25000000.0, $register->holdingAt($this->a, CarbonImmutable::parse('2026-05-31'))['holding_value'], 'the older value applies until the new valuation date');

        $history = collect($this->getJson("/api/v1/shares/share-holders/{$this->a->id}")->assertOk()->json('data.history'))->keyBy('date');
        $this->assertEquals(25000000, $history['2026-01-01']['holding_value']);
        $this->assertEquals(37500000, $history['2026-06-01']['holding_value']);
        $this->assertEquals(50000000, $history['2026-09-01']['holding_value']);
        $this->assertEquals(500, $history['2026-09-13']['shares']);

        $this->revalue(60000, '2026-05-01')->assertUnprocessable()->assertJsonValidationErrors('valuation_date');
        $this->assertSame(3, ShareValuation::count(), 'history is never rewritten by a back-dated value');
    }

    public function test_ownership_as_of_a_past_date_replays_the_register(): void
    {
        $this->establish(['established_on' => '2026-01-01']);
        // A paid issuance posts at its approval date (C6), so the back-dated movement here is a bonus issuance.
        $this->issue($this->c, 250, ['type' => 'bonus_issuance', 'issue_date' => '2026-03-01'])->assertCreated();
        $this->transfer($this->a, $this->c, 100)->assertCreated();

        $this->getJson('/api/v1/shares/register?as_of=2026-02-01')->assertOk()
            ->assertJsonPath('data.total_shares', 1000)
            ->assertJsonPath('data.rows.0.shares', 500)
            ->assertJsonPath('data.rows.0.ownership_percent', 50)
            ->assertJsonPath('data.rows.2.shares', 0);

        $this->getJson('/api/v1/shares/reports/ownership?as_of=2026-03-01')->assertOk()
            ->assertJsonPath('data.total_shares', 1250)
            ->assertJsonPath('data.rows.2.ownership_percent', 20);

        $this->getJson('/api/v1/shares/register')->assertOk()
            ->assertJsonPath('data.total_shares', 1250)
            ->assertJsonPath('data.rows.0.shares', 400)
            ->assertJsonPath('data.rows.2.shares', 350)
            ->assertJsonPath('data.rows.2.ownership_percent', 28);
    }

    public function test_current_positions_always_equal_a_replay_of_the_transaction_history(): void
    {
        $this->establish();
        $this->issue($this->c, 250, ['type' => 'bonus_issuance']);
        $this->transfer($this->a, $this->c, 120);
        $this->transfer($this->c, $this->b, 70);
        $this->postJson('/api/v1/shares/cancellations', ['share_holder_id' => $this->b->id, 'shares' => 30, 'reason' => 'Buy-back'])->assertCreated();
        $this->postJson('/api/v1/shares/adjustments', ['share_holder_id' => $this->a->id, 'direction' => 'increase', 'shares' => 5, 'reason' => 'Correction'])->assertCreated();
        $transfer = ShareTransaction::where('type', ShareTransactionType::Transfer)->orderBy('id')->firstOrFail();
        $this->postJson("/api/v1/shares/transactions/{$transfer->id}/reverse", ['reason' => 'Entered in error'])->assertCreated();

        $register = app(ShareRegister::class);
        $positions = SharePosition::where('company_id', $this->admin->company_id)->pluck('shares', 'share_holder_id')->map(fn ($shares): int => (int) $shares)->sortKeys()->all();
        $replay = $register->replay($this->admin->company_id)->sortKeys()->all();

        $this->assertSame($replay, $positions);
        $this->assertSame([$this->a->id => 505, $this->b->id => 540, $this->c->id => 180], $positions);
        $this->assertSame(['consistent' => true, 'mismatches' => []], $register->verify($this->admin->company_id));
        $this->assertSame(1225, $register->issuedShares($this->admin->company_id));
        $this->assertSame(1225, (int) ShareTransaction::all()->sum(fn (ShareTransaction $row): int => $row->issuedDelta()));
        $this->assertGreaterThanOrEqual(0, SharePosition::min('shares'), 'no negative holding');
    }

    public function test_paid_issuance_posts_a_balanced_share_capital_journal_through_the_capital_contribution_service(): void
    {
        Storage::fake(Capital::DISK);
        $this->establish();
        $bank = BankAccount::create(['company_id' => $this->admin->company_id, 'name' => 'NMB']);

        $id = $this->post('/api/v1/shares/issuances', [
            'share_holder_id' => $this->c->id, 'type' => 'issuance', 'payment_treatment' => 'paid', 'shares' => '250', 'price_per_share' => '60,000',
            'pay_method' => 'BANK', 'bank_account_id' => $bank->id, 'receipt_number' => 'RC-9', 'idempotency_key' => 'issue-1',
            'document' => UploadedFile::fake()->create('subscription.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(202)->json('data.id');
        $this->assertSame([0, 0, 0], [Capital::count(), JournalEntry::count(), ShareTransaction::where('type', ShareTransactionType::Issuance)->count()], 'a pending paid issuance posts nothing');
        $this->approveAsSecondUser($this->admin, "/api/v1/shares/issuance-requests/{$id}/approve");

        $transaction = ShareTransaction::where('type', ShareTransactionType::Issuance)->sole();
        $response = $this->getJson("/api/v1/shares/transactions/{$transaction->id}")->assertOk();
        $capital = Capital::findOrFail($transaction->capital_id);
        $this->assertSame([15000000.0, 'BANK', $bank->id, 'RC-9', $this->c->id], [(float) $capital->amount, $capital->pay_method, $capital->bank_account_id, $capital->receipt_number, $capital->share_holder_id]);
        $this->assertSame($capital->journal_entry_id, $transaction->journal_entry_id);
        Storage::disk(Capital::DISK)->assertExists($capital->receipt_file);

        $lines = JournalLine::with('account')->where('journal_entry_id', $transaction->journal_entry_id)->orderBy('id')->get();
        $this->assertSame([Account::Bank, Account::Capital], $lines->map(fn (JournalLine $line) => $line->account->key)->all());
        $this->assertEquals([15000000, 0], [$lines[0]->debit, $lines[0]->credit]);
        $this->assertEquals([0, 15000000], [$lines[1]->debit, $lines[1]->credit]);
        $this->assertEquals($lines->sum('debit'), $lines->sum('credit'), 'debits equal credits');
        $this->assertSame(15000000.0, $this->ledger()->balance($this->admin->company_id, Account::Bank, bankAccount: $bank));
        $this->assertSame(15000000.0, $this->ledger()->balance($this->admin->company_id, Account::Capital));

        $this->getJson('/api/v1/capital/capitals')->assertOk()
            ->assertJsonPath('data.share_holders.2.capitals.0.share_transaction_reference', $transaction->reference)
            ->assertJsonPath('data.share_holders.2.capitals.0.journal_reference', $transaction->journalEntry->reference);
        $this->assertStringStartsWith("capital/capitals/{$capital->id}/receipt", $response->json('data.document_endpoint'));
    }

    public function test_initial_allocation_linked_to_recorded_contributions_posts_no_second_journal(): void
    {
        $first = $this->contribution($this->a, 25000000);
        $second = $this->contribution($this->b, 25000000);
        $journals = JournalEntry::count();

        $this->establish(['allocations' => [
            ['share_holder_id' => $this->a->id, 'shares' => 500, 'treatment' => 'linked_contribution', 'capital_id' => $first],
            ['share_holder_id' => $this->b->id, 'shares' => 500, 'treatment' => 'linked_contribution', 'capital_id' => $second],
        ]])->assertCreated();

        $this->assertSame($journals, JournalEntry::count(), 'founder capital already recorded is not posted again');
        $this->assertSame(50000000.0, $this->ledger()->balance($this->admin->company_id, Account::Capital));
        $this->assertSame([$first, $second], ShareTransaction::orderBy('id')->pluck('capital_id')->all());
        $this->assertSame(Capital::find($first)->journal_entry_id, ShareTransaction::orderBy('id')->first()->journal_entry_id);

        $this->issue($this->a, 10, ['payment_treatment' => 'linked_contribution', 'capital_id' => $first])
            ->assertUnprocessable()->assertJsonValidationErrors('capital_id');
        $this->issue($this->c, 10, ['payment_treatment' => 'linked_contribution', 'capital_id' => $second])
            ->assertUnprocessable()->assertJsonValidationErrors('capital_id');
    }

    public function test_allocation_without_cash_bonus_issuance_and_transfers_post_no_ledger_entries(): void
    {
        $this->establish(['allocations' => [
            ['share_holder_id' => $this->a->id, 'shares' => 500, 'treatment' => 'no_cash'],
            ['share_holder_id' => $this->b->id, 'shares' => 500, 'treatment' => 'paid', 'pay_method' => 'CASH'],
        ]])->assertCreated();

        $this->assertSame(1, JournalEntry::count(), 'only the paid founder posts a journal');
        $this->assertSame(25000000.0, $this->ledger()->balance($this->admin->company_id, Account::Company));

        $this->issue($this->c, 100, ['type' => 'bonus_issuance'])->assertCreated()
            ->assertJsonPath('data.payment_treatment', 'no_cash')
            ->assertJsonPath('data.journal_entry_id', null)
            ->assertJsonPath('data.total_amount', null);
        $this->transfer($this->c, $this->a, 40)->assertCreated();

        $this->assertSame(1, JournalEntry::count());
        $this->assertSame(25000000.0, $this->ledger()->balance($this->admin->company_id, Account::Company), 'company cash is unchanged');
        $this->issue($this->c, 1, ['payment_treatment' => ''])->assertUnprocessable()->assertJsonValidationErrors('payment_treatment');
    }

    public function test_transfer_rules_reject_same_holder_zero_shares_and_overspending(): void
    {
        $this->establish();

        $this->transfer($this->a, $this->a, 10)->assertUnprocessable()->assertJsonValidationErrors('to_share_holder_id');
        $this->transfer($this->a, $this->c, 0)->assertUnprocessable()->assertJsonValidationErrors('shares');
        $this->transfer($this->a, $this->c, 501)->assertUnprocessable()->assertJsonValidationErrors('shares');
        $this->transfer($this->c, $this->a, 1)->assertUnprocessable()->assertJsonValidationErrors('shares');

        $this->assertSame(2, ShareTransaction::count());
        $this->transfer($this->a, $this->c, 500)->assertCreated();
        $this->assertSame(0, SharePosition::where('share_holder_id', $this->a->id)->value('shares'));
    }

    public function test_issuance_respects_the_authorised_share_limit(): void
    {
        $this->establish(['authorised_shares' => 1200]);

        $this->issue($this->c, 201, ['type' => 'bonus_issuance'])->assertUnprocessable()->assertJsonValidationErrors('shares');
        $this->issue($this->c, 200, ['type' => 'bonus_issuance'])->assertCreated();
        $this->getJson('/api/v1/shares/overview')->assertJsonPath('data.available_shares', 0);

        $this->patchJson('/api/v1/shares/structure', ['authorised_shares' => 1100])->assertUnprocessable()->assertJsonValidationErrors('authorised_shares');
        $this->patchJson('/api/v1/shares/structure', ['authorised_shares' => 1500])->assertOk();
        $this->getJson('/api/v1/shares/overview')->assertJsonPath('data.available_shares', 300);
        $this->patchJson('/api/v1/shares/structure', ['authorised_shares' => null])->assertOk();
        $this->getJson('/api/v1/shares/overview')->assertJsonPath('data.available_shares', null);
    }

    public function test_double_submission_with_the_same_idempotency_key_records_once(): void
    {
        $this->establish(['idempotency_key' => 'setup-1'])->assertCreated();
        $this->establish(['idempotency_key' => 'setup-1'])->assertOk()->assertJsonPath('message', 'Share structure was already created');
        $this->establish(['idempotency_key' => 'setup-2'])->assertUnprocessable();

        $issue = ['payment_treatment' => 'paid', 'pay_method' => 'CASH', 'idempotency_key' => 'issue-key'];
        $request = $this->issue($this->c, 250, $issue)->assertStatus(202)->json('data.id');
        $this->issue($this->c, 250, $issue)->assertOk()->assertJsonPath('message', 'The share issuance was already requested')->assertJsonPath('data.id', $request);
        $this->issue($this->c, 251, $issue)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->approveAsSecondUser($this->admin, "/api/v1/shares/issuance-requests/{$request}/approve");
        $this->asApprover($this->admin, fn () => $this->postJson("/api/v1/shares/issuance-requests/{$request}/approve")->assertUnprocessable());

        $this->transfer($this->a, $this->c, 100, ['idempotency_key' => 'transfer-key'])->assertCreated();
        $this->transfer($this->a, $this->c, 100, ['idempotency_key' => 'transfer-key'])->assertOk();

        $this->revalue(90000, '2026-09-13', ['idempotency_key' => 'value-key'])->assertCreated();
        $this->revalue(90000, '2026-09-13', ['idempotency_key' => 'value-key'])->assertOk();

        $this->assertSame(1, ShareStructure::count());
        $this->assertSame(1, ShareTransaction::where('type', ShareTransactionType::Issuance)->count());
        $this->assertSame(1, Capital::count(), 'the payment is recorded once');
        $this->assertSame(1, JournalEntry::count(), 'the share capital journal is posted once');
        $this->assertSame(1, ShareTransaction::where('type', ShareTransactionType::Transfer)->count());
        $this->assertSame(2, ShareValuation::count());
        $this->assertSame(400, SharePosition::where('share_holder_id', $this->a->id)->value('shares'));
    }

    public function test_concurrent_transfers_against_the_same_position_cannot_overspend(): void
    {
        $this->establish();

        $stale = SharePosition::where('share_holder_id', $this->a->id)->firstOrFail();
        $this->assertSame(500, $stale->shares, 'both requests saw 500 shares before either ran');

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $transfers = app(ShareTransfers::class);
        $transfers->transfer($this->a, $this->b, 400, null, null, null, $this->admin, 'race-1');

        try {
            $transfers->transfer($this->a, $this->c, 400, null, null, null, $this->admin, 'race-2');
            $this->fail('The second transfer must be rejected');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('shares', $exception->errors());
        }

        $locks = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from `share_positions`') && str_contains($sql, 'for update'));
        $this->assertCount(2, $locks, 'each transfer re-reads the source position under a row lock');
        $this->assertSame([$this->a->id => 100, $this->b->id => 900], SharePosition::orderBy('share_holder_id')->pluck('shares', 'share_holder_id')->map(fn ($shares): int => (int) $shares)->all());
        $this->assertSame(1000, app(ShareRegister::class)->issuedShares($this->admin->company_id));
        $this->assertSame(1, ShareTransaction::where('type', ShareTransactionType::Transfer)->count());
        $this->assertNull(ShareTransaction::where('idempotency_key', 'race-2')->first());
    }

    public function test_failed_operations_leave_no_partial_rows(): void
    {
        $bank = BankAccount::create(['company_id' => $this->admin->company_id, 'name' => 'NMB']);
        $foreign = $this->contribution($this->a, 1000);

        $this->establish(['allocations' => [
            ['share_holder_id' => $this->b->id, 'shares' => 500, 'treatment' => 'paid', 'pay_method' => 'BANK', 'bank_account_id' => $bank->id],
            ['share_holder_id' => $this->c->id, 'shares' => 500, 'treatment' => 'linked_contribution', 'capital_id' => $foreign],
        ]])->assertUnprocessable()->assertJsonValidationErrors('capital_id');

        $this->assertSame(0, ShareStructure::count());
        $this->assertSame(0, ShareValuation::count());
        $this->assertSame(0, ShareTransaction::count());
        $this->assertSame(0, (int) SharePosition::sum('shares'));
        $this->assertSame(1, Capital::count(), 'the paid founder contribution was rolled back');
        $this->assertSame(1, JournalEntry::count());
        $this->assertSame(0.0, $this->ledger()->balance($this->admin->company_id, Account::Bank, bankAccount: $bank));

        $this->establish(['established_on' => '2026-07-01'])->assertCreated();
        AccountingPeriod::create(['company_id' => $this->admin->company_id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => 'closed']);
        // C6: a paid issuance is only requested (nothing posted); its journal is dated the approval date, never a closed period.
        $this->issue($this->c, 100, ['payment_treatment' => 'paid', 'pay_method' => 'CASH', 'issue_date' => '2026-08-15'])->assertStatus(202);

        $this->assertSame(2, ShareTransaction::count());
        $this->assertSame(1, Capital::count());
        $this->assertSame(1, JournalEntry::count());
        $this->assertSame(1000, app(ShareRegister::class)->issuedShares($this->admin->company_id));
        $this->assertTrue(app(ShareRegister::class)->verify($this->admin->company_id)['consistent']);
    }

    public function test_permissions_are_checked_before_validation_and_hide_shares_from_other_roles(): void
    {
        $this->establish();
        $roles = ['admin', 'finance', 'branch_manager', 'loan_officer', 'teller'];

        foreach ($roles as $key) {
            $employee = $this->employee($key);
            $this->actingAs($employee);
            foreach (['overview', 'register', 'share-holders', 'transactions', 'valuations', 'reports/ownership', "share-holders/{$this->a->id}"] as $path) {
                $this->getJson("/api/v1/shares/{$path}")->assertForbidden();
            }
            foreach (['structure', 'issuances', 'transfers', 'valuations', 'cancellations', 'adjustments'] as $path) {
                $this->postJson("/api/v1/shares/{$path}", [])->assertForbidden();
            }
            $this->assertNotContains('shares.view', $employee->fresh()->permissionKeys());
        }

        $viewer = $this->employee('finance');
        $viewer->role->permissions()->create(['permission' => 'shares.view']);
        $this->actingAs($viewer->fresh());
        $this->getJson('/api/v1/shares/overview')->assertOk();
        $this->getJson("/api/v1/shares/share-holders/{$this->a->id}")->assertOk()->assertJsonPath('data.can_view_contributions', false)->assertJsonPath('data.contributions', null);
        $this->postJson('/api/v1/shares/issuances', [])->assertForbidden();
        $this->postJson('/api/v1/shares/transfers', [])->assertForbidden();
        $this->postJson('/api/v1/shares/valuations', [])->assertForbidden();
        $transaction = ShareTransaction::firstOrFail();
        $this->postJson("/api/v1/shares/transactions/{$transaction->id}/reverse", [])->assertForbidden();

        $this->actingAs($this->admin);
        $this->postJson('/api/v1/shares/issuances', [])->assertUnprocessable();
        $this->assertSame(['shares.issue', 'shares.manage', 'shares.transfer', 'shares.value', 'shares.view'], DB::table('role_permissions')->where('role_id', $this->admin->role_id)->where('permission', 'like', 'shares.%')->orderBy('permission')->pluck('permission')->all());
    }

    public function test_dividends_are_split_by_share_register_ownership_on_the_declaration_date(): void
    {
        $this->contribution($this->a, 90000000);
        $this->contribution($this->b, 10000000);
        $this->establish(['allocations' => [
            ['share_holder_id' => $this->a->id, 'shares' => 500, 'treatment' => 'no_cash'],
            ['share_holder_id' => $this->b->id, 'shares' => 500, 'treatment' => 'no_cash'],
        ]]);
        $this->transfer($this->a, $this->c, 100);
        $this->ledger()->journal($this->admin->company_id, 'MONTH END PROFIT', [
            ['account' => Account::Interest, 'debit' => 1000000, 'branch' => $this->admin->branch_id],
            ['account' => Account::RetainedProfit, 'credit' => 1000000, 'branch' => $this->admin->branch_id],
        ]);
        $period = AccountingPeriod::create(['company_id' => $this->admin->company_id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => AccountingPeriod::STATUS_CLOSED, 'closed_at' => now(), 'commission_calculated_at' => now()]);
        BranchPeriodResult::create(['accounting_period_id' => $period->id, 'branch_id' => $this->admin->branch_id, 'gross_profit' => 1000000, 'net_profit' => 1000000, 'distributable_profit' => 1000000, 'commission_eligible' => true]);

        $this->getJson('/api/v1/capital/dividends/preview')->assertOk()
            ->assertJsonPath('data.rows.0.contribution_total', 90000000)
            ->assertJsonPath('data.rows.0.shares', 400)
            ->assertJsonPath('data.rows.0.ownership_percent', 40)
            ->assertJsonPath('data.rows.1.ownership_percent', 50)
            ->assertJsonPath('data.rows.2.ownership_percent', 10);

        $requestId = $this->postJson('/api/v1/capital/dividends', ['period' => '2026-08'])->assertCreated()->assertJsonPath('data.profit_amount', 1000000)->json('data.id');
        $this->approveAsSecondUser($this->admin, "/api/v1/capital/dividends/requests/{$requestId}/approve");

        $allocations = DividendAllocation::orderBy('id')->get();
        $this->assertSame([120000.0, 150000.0, 30000.0], $allocations->map(fn (DividendAllocation $row): float => (float) $row->amount)->all(), 'contributions (90/10) do not decide the split');
        $this->assertSame([400, 500, 100], $allocations->pluck('shares_held')->map(fn ($shares): int => (int) $shares)->all());
        $this->assertSame([40.0, 50.0, 10.0], $allocations->map(fn (DividendAllocation $row): float => (float) $row->share_percent)->all());
    }

    public function test_reversal_restores_positions_and_keeps_the_original_row(): void
    {
        $this->establish();
        $issued = $this->issue($this->c, 250, ['type' => 'bonus_issuance'])->json('data.id');

        $this->postJson("/api/v1/shares/transactions/{$issued}/reverse", ['reason' => 'Issued to the wrong investor'])->assertCreated()
            ->assertJsonPath('data.type', 'reversal')
            ->assertJsonPath('data.from_share_holder_id', $this->c->id)
            ->assertJsonPath('data.issued_change', -250);
        $this->postJson("/api/v1/shares/transactions/{$issued}/reverse", ['reason' => 'Again'])->assertUnprocessable();

        $original = ShareTransaction::findOrFail($issued);
        $this->assertSame([ShareTransaction::REVERSED, 250, $this->c->id], [$original->status, $original->shares, $original->to_share_holder_id]);
        $this->assertSame(1000, app(ShareRegister::class)->issuedShares($this->admin->company_id));
        $this->assertSame(0, SharePosition::where('share_holder_id', $this->c->id)->value('shares'));

        $reversal = ShareTransaction::where('reversal_of_id', $issued)->sole();
        $this->postJson("/api/v1/shares/transactions/{$reversal->id}/reverse", ['reason' => 'x x x'])->assertUnprocessable();

        $this->expectException(LogicException::class);
        $original->update(['shares' => 1]);
    }

    public function test_share_transactions_and_valuations_are_immutable(): void
    {
        $this->establish();
        $this->revalue(80000, '2026-09-13');

        foreach ([fn () => ShareTransaction::firstOrFail()->update(['shares' => 999]), fn () => ShareTransaction::firstOrFail()->delete(), fn () => ShareValuation::latest('id')->firstOrFail()->update(['new_value' => 1]), fn () => ShareValuation::firstOrFail()->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('Share history must not be edited or deleted');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $latest = ShareValuation::where('kind', 'revaluation')->sole();
        $this->postJson("/api/v1/shares/valuations/{$latest->id}/reverse", ['reason' => 'Wrong figure'])->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->assertSame(80000.0, (float) $latest->fresh()->new_value, 'a reversed valuation keeps its figures');
        $this->assertSame(50000.0, app(ShareRegister::class)->currentValue($this->admin->company_id));
        $initial = ShareValuation::where('kind', 'initial')->sole();
        $this->postJson("/api/v1/shares/valuations/{$initial->id}/reverse", ['reason' => 'Wrong figure'])->assertUnprocessable();
    }

    public function test_cancellation_reduces_total_issued_shares(): void
    {
        $this->establish();

        $this->postJson('/api/v1/shares/cancellations', ['share_holder_id' => $this->b->id, 'shares' => 600, 'reason' => 'Buy-back'])->assertUnprocessable()->assertJsonValidationErrors('shares');
        $this->postJson('/api/v1/shares/cancellations', ['share_holder_id' => $this->b->id, 'shares' => 250, 'reason' => 'Buy-back'])->assertCreated()->assertJsonPath('data.issued_change', -250);

        $this->assertRegister([$this->a->id => [500, 66.6667, 25000000], $this->b->id => [250, 33.3333, 12500000]], 750, 50000);
    }

    public function test_every_share_action_is_written_to_the_audit_trail(): void
    {
        $this->establish();
        $this->issue($this->c, 10, ['type' => 'bonus_issuance']);
        $this->transfer($this->a, $this->c, 5);
        $this->revalue(55000, '2026-09-13');
        $this->patchJson('/api/v1/shares/structure', ['authorised_shares' => 5000])->assertOk();
        $this->postJson('/api/v1/shares/transactions/'.ShareTransaction::latest('id')->value('id').'/reverse', ['reason' => 'Mistake'])->assertCreated();

        $actions = AuditLog::where('action', 'like', 'Share%')->pluck('action')->countBy();
        $this->assertSame(1, $actions['ShareStructure.created']);
        $this->assertSame(1, $actions['ShareStructure.updated']);
        $this->assertSame(2, $actions['ShareValuation.created']);
        $this->assertSame(5, $actions['ShareTransaction.created'], 'allocation ×2, issuance, transfer, reversal');
        $this->assertSame(1, $actions['ShareTransaction.updated'], 'the reversed status');
        $this->assertSame($this->admin->id, AuditLog::where('action', 'ShareTransaction.created')->latest('id')->value('employee_id'));
    }

    public function test_share_profile_shareholders_list_and_reports_read_the_register(): void
    {
        $this->contribution($this->a, 25000000);
        $this->establish();
        $this->issuePaid($this->c, 250, ['pay_method' => 'CASH']);
        $this->transfer($this->a, $this->c, 100, ['consideration_per_share' => 50000]);

        $this->getJson('/api/v1/shares/share-holders')->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'ALPHA HOLDER')
            ->assertJsonPath('data.0.shares', 400)
            ->assertJsonPath('data.2.ownership_percent', 28);

        $this->getJson("/api/v1/shares/share-holders/{$this->a->id}")->assertOk()
            ->assertJsonPath('data.holding.shares', 400)
            ->assertJsonPath('data.holding.holding_value', 20000000)
            ->assertJsonPath('data.holding.date_acquired', '2026-09-13')
            ->assertJsonPath('data.can_view_contributions', true)
            ->assertJsonPath('data.total_contributed', 25000000)
            ->assertJsonCount(2, 'data.transactions');

        $this->getJson('/api/v1/shares/reports/distribution')->assertOk()
            ->assertJsonPath('data.holders', 3)
            ->assertJsonPath('data.largest_percent', 40)
            ->assertJsonPath('data.rows.0.rank', 1);
        $this->getJson('/api/v1/shares/reports/issuances')->assertOk()
            ->assertJsonPath('data.totals.shares', 1250)
            ->assertJsonPath('data.totals.paid_amount', 12500000);
        $this->getJson('/api/v1/shares/reports/transfers')->assertOk()->assertJsonPath('data.totals.shares', 100)->assertJsonPath('data.totals.consideration', 5000000);
        $this->getJson('/api/v1/shares/reports/valuations')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/shares/reports/transactions')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/v1/shares/transactions?type=transfer')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/capital/share-holders')->assertOk()->assertJsonPath('data.0.shares', 400)->assertJsonPath('data.0.ownership_percent', 32);
        $this->assertSame(32.0, app(ShareholderOwnership::class)->forShareHolder($this->a)['ownership_percent']);
        $this->deleteJson("/api/v1/capital/share-holders/{$this->c->id}")->assertUnprocessable();
    }

    /**
     * A CASH contribution recorded by the admin and approved by a second authorised user (rule 6); returns its id.
     */
    private function contribution(ShareHolder $holder, float $amount): int
    {
        $id = $this->postJson('/api/v1/capital/capitals', ['share_id' => $holder->id, 'amount' => $amount, 'pay_method' => 'CASH'])->assertCreated()->json('data.id');
        $this->approveAsSecondUser($this->admin, "/api/v1/capital/capitals/{$id}/approve");

        return $id;
    }

    private function holder(string $firstName): ShareHolder
    {
        return ShareHolder::create(['company_id' => $this->admin->company_id, 'first_name' => $firstName, 'last_name' => 'HOLDER', 'mobile' => '0777', 'email' => strtolower($firstName).'@example.com', 'date_of_birth' => '1990-01-01']);
    }

    private function employee(string $role): Employee
    {
        return Employee::factory()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->admin->branch_id,
            'role_id' => $this->admin->company->roles()->where('key', $role)->value('id'),
        ]);
    }

    private function ledger(): Ledger
    {
        return app(Ledger::class);
    }

    /**
     * 50,000,000 capital basis ÷ 1,000 shares, founders ALPHA 500 / BETA 500 allocated without cash.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function establish(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/shares/structure', $overrides + [
            'capital_basis' => '50,000,000',
            'total_shares' => 1000,
            'authorised_shares' => 2000,
            'established_on' => '2026-09-13',
            'allocations' => [
                ['share_holder_id' => $this->a->id, 'shares' => 500, 'treatment' => 'no_cash'],
                ['share_holder_id' => $this->b->id, 'shares' => 500, 'treatment' => 'no_cash'],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function revalue(float $value, string $date, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/shares/valuations', $extra + ['new_value' => $value, 'valuation_date' => $date, 'reason' => 'Board valuation']);
    }

    /**
     * Request a paid issuance and have a second authorised user approve it (C6 maker/checker); returns the approval response.
     *
     * @param  array<string, mixed>  $extra
     */
    private function issuePaid(ShareHolder $holder, int $shares, array $extra = []): TestResponse
    {
        $id = $this->issue($holder, $shares, ['payment_treatment' => 'paid'] + $extra)->assertStatus(202)->json('data.id');

        return $this->approveAsSecondUser($this->admin, "/api/v1/shares/issuance-requests/{$id}/approve");
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function issue(ShareHolder $holder, int $shares, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/shares/issuances', $extra + ['share_holder_id' => $holder->id, 'type' => 'issuance', 'shares' => $shares]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transfer(ShareHolder $from, ShareHolder $to, int $shares, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/shares/transfers', $extra + ['from_share_holder_id' => $from->id, 'to_share_holder_id' => $to->id, 'shares' => $shares]);
    }

    /**
     * @param  array<int, array{0: int, 1: float|int, 2: float|int}>  $expected  share holder id => [shares, ownership %, holding value]
     */
    private function assertRegister(array $expected, int $totalShares, float|int $shareValue): void
    {
        $service = app(ShareRegister::class)->register($this->admin->company_id)->keyBy(fn (array $row): int => $row['share_holder']->id);
        $api = $this->getJson('/api/v1/shares/register')->assertOk();
        $rows = collect($api->json('data.rows'))->keyBy('share_holder_id');

        $this->assertSame($totalShares, $api->json('data.total_shares'));
        $this->assertEquals($shareValue, $api->json('data.share_value'));
        $this->assertEquals($totalShares * $shareValue, $api->json('data.total_valuation'));

        foreach ($expected as $id => [$shares, $percent, $holding]) {
            $this->assertSame($shares, $service[$id]['shares']);
            $this->assertEquals($percent, $service[$id]['ownership_percent']);
            $this->assertEquals($holding, $service[$id]['holding_value']);
            $this->assertSame($shares, $rows[$id]['shares']);
            $this->assertEquals($percent, $rows[$id]['ownership_percent']);
            $this->assertEquals($shareValue, $rows[$id]['share_value']);
            $this->assertEquals($holding, $rows[$id]['holding_value']);
        }
    }
}

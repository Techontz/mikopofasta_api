<?php

namespace Tests\Feature\Api\Capital;

use App\Enums\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\FloatTransfer;
use App\Models\JournalEntry;
use App\Services\FloatService;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

class FloatReversalApiTest extends TestCase
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

    public function test_company_float_reversal_restores_both_balances_and_keeps_the_original(): void
    {
        $this->ledger->openingBalance($this->admin->company_id, Account::Company, 1000000);
        $this->approveFloat($this->postJson('/api/v1/capital/floats', ['amount' => 400000, 'from_account' => Account::Company->value])->assertCreated()->json('data.id'));
        $transfer = FloatTransfer::firstOrFail();
        $this->assertNotNull($transfer->journal_entry_id);

        $this->getJson('/api/v1/capital/floats')->assertOk()->assertJsonPath('data.0.can_reverse', true)->assertJsonPath('data.0.reverse_blocked_reason', null);

        $this->postJson("/api/v1/capital/floats/{$transfer->id}/reverse", ['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/capital/floats/{$transfer->id}/reverse", ['reason' => 'Wrong source selected'])->assertOk()->assertJsonPath('message', 'Float Reversed successfully');

        $this->assertSame(1000000.0, $this->ledger->balance($this->admin->company_id, Account::Company));
        $this->assertSame(0.0, $this->ledger->balance($this->admin->company_id, Account::Principal));

        $transfer->refresh();
        $this->assertSame('reversed', $transfer->status);
        $this->assertSame($this->admin->id, $transfer->reversed_by);
        $this->assertSame('Wrong source selected', $transfer->reversal_reason);
        $this->assertNotNull($transfer->reversed_at);
        $reversal = JournalEntry::findOrFail($transfer->reversal_journal_entry_id);
        $this->assertSame($transfer->journal_entry_id, $reversal->reversal_of_id);
        $this->assertTrue(AuditLog::where('action', 'FloatTransfer.reversed')->where('auditable_id', $transfer->id)->exists());

        $this->getJson('/api/v1/capital/floats')->assertOk()
            ->assertJsonPath('data.0.status', 'reversed')
            ->assertJsonPath('data.0.reversal_reason', 'Wrong source selected')
            ->assertJsonPath('data.0.can_reverse', false)
            ->assertJsonPath('total', 0)
            ->assertJsonPath('total_reversed', 400000);

        $this->postJson("/api/v1/capital/floats/{$transfer->id}/reverse", ['reason' => 'Again'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame(3, JournalEntry::count());
    }

    public function test_reversal_is_blocked_when_the_receiving_account_already_used_the_money(): void
    {
        $this->ledger->openingBalance($this->admin->company_id, Account::Company, 5000);
        // The float lands in the HQ PRINCIPAL A/C (no branch), so HQ spends part of it before the reversal is attempted.
        $this->approveFloat($this->postJson('/api/v1/capital/floats', ['amount' => 1000, 'from_account' => Account::Company->value])->assertCreated()->json('data.id'));
        $float = FloatTransfer::firstOrFail();
        $spent = $this->ledger->transfer($this->admin->company_id, ['account' => Account::Principal], ['account' => Account::HqDisbursement], 600, 'HQ DISBURSEMENT');
        $entries = JournalEntry::count();

        $message = 'The receiving account (PRINCIPAL A/C) no longer holds TZS 1,000 (balance TZS 400); the money has already been used.';
        $this->getJson('/api/v1/capital/floats')->assertOk()->assertJsonPath('data.0.can_reverse', false)->assertJsonPath('data.0.reverse_blocked_reason', $message);
        $this->postJson("/api/v1/capital/floats/{$float->id}/reverse", ['reason' => 'Mistake'])->assertUnprocessable()->assertJsonPath('errors.reason.0', $message);

        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame('approved', $float->fresh()->status);

        $this->ledger->reverse($spent, 'Undo disbursement');
        $this->assertSame(1000.0, $this->ledger->balance($this->admin->company_id, Account::Principal));
        $this->assertSame(0.0, $this->ledger->balance($this->admin->company_id, Account::HqDisbursement));

        $this->postJson("/api/v1/capital/floats/{$float->id}/reverse", ['reason' => 'Mistake'])->assertOk();
        $this->assertSame(5000.0, $this->ledger->balance($this->admin->company_id, Account::Company));
    }

    public function test_pending_transfers_cannot_be_reversed_and_a_legacy_branch_float_still_reverses(): void
    {
        $this->ledger->openingBalance($this->admin->company_id, Account::Company, 250000);
        $id = $this->postJson('/api/v1/capital/floats', ['amount' => 100000, 'from_account' => Account::Company->value])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/capital/floats/{$id}/reverse", ['reason' => 'Not yet'])->assertUnprocessable()->assertJsonPath('errors.reason.0', 'Only approved transfers can be reversed.');

        $this->approveFloat($id);
        $this->getJson('/api/v1/capital/floats/approved')->assertOk()->assertJsonPath('data.0.can_reverse', true);
        $this->postJson("/api/v1/capital/floats/{$id}/reverse", ['reason' => 'Wrong source'])->assertOk();

        $this->assertSame(250000.0, $this->ledger->balance($this->admin->company_id, Account::Company));
        $this->assertSame(0.0, $this->ledger->balance($this->admin->company_id, Account::Principal));
        $this->getJson('/api/v1/capital/floats/approved')->assertOk()->assertJsonPath('data.0.status', 'reversed')->assertJsonPath('total', 0);

        // Branch → branch floats can no longer be requested, but the historic rows are still approved and reversed.
        $kakonko = Branch::factory()->create(['company_id' => $this->admin->company_id]);
        $this->ledger->openingBalance($this->admin->company_id, Account::Principal, 250000, branch: $this->admin->branch_id);
        $legacy = FloatTransfer::create(['company_id' => $this->admin->company_id, 'type' => 'branch_to_branch', 'from_branch_id' => $this->admin->branch_id, 'to_branch_id' => $kakonko->id, 'from_account' => Account::Principal->value, 'to_account' => Account::Principal->value, 'amount' => 100000, 'status' => 'pending', 'transfer_date' => today()]);

        $this->postJson("/api/v1/capital/floats/{$legacy->id}/reverse", ['reason' => 'Not yet'])->assertUnprocessable()->assertJsonPath('errors.reason.0', 'Only approved transfers can be reversed.');
        $this->asApprover($this->admin, fn () => $this->postJson("/api/v1/capital/floats/{$legacy->id}/approve")->assertOk());
        $this->assertSame(100000.0, $this->ledger->balance($this->admin->company_id, Account::Principal, $kakonko->id));

        $this->postJson("/api/v1/capital/floats/{$legacy->id}/reverse", ['reason' => 'Wrong receiver'])->assertOk();
        $this->assertSame(250000.0, $this->ledger->balance($this->admin->company_id, Account::Principal, $this->admin->branch_id));
        $this->assertSame(0.0, $this->ledger->balance($this->admin->company_id, Account::Principal, $kakonko->id));
        $this->assertSame('reversed', $legacy->fresh()->status);
    }

    public function test_concurrent_approvals_of_one_pending_transfer_post_once(): void
    {
        $this->ledger->openingBalance($this->admin->company_id, Account::Company, 250000);
        $service = app(FloatService::class);
        $transfer = $service->requestCompanyToHq($this->admin->company_id, Account::Company, null, 100000, $this->admin);
        $stale = FloatTransfer::findOrFail($transfer->id);

        $service->approve($transfer);
        $entries = JournalEntry::count();

        try {
            $service->approve($stale);
            $this->fail('The second approval must be rejected.');
        } catch (ValidationException) {
        }

        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame(150000.0, $this->ledger->balance($this->admin->company_id, Account::Company));
        $this->assertSame(100000.0, $this->ledger->balance($this->admin->company_id, Account::Principal));
    }

    public function test_reversal_rolls_back_when_posting_fails(): void
    {
        $this->ledger->openingBalance($this->admin->company_id, Account::Company, 1000);
        $this->approveFloat($this->postJson('/api/v1/capital/floats', ['amount' => 1000, 'from_account' => Account::Company->value])->assertCreated()->json('data.id'));
        $transfer = FloatTransfer::firstOrFail();
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
            $this->postJson("/api/v1/capital/floats/{$transfer->id}/reverse", ['reason' => 'Mistake']);
            $this->fail('The posting failure must surface.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Ledger failure', $exception->getMessage());
        }

        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame('approved', $transfer->fresh()->status);
        $this->assertSame(1000.0, $real->balance($this->admin->company_id, Account::Principal));
    }

    public function test_permissions_and_company_isolation(): void
    {
        $this->ledger->openingBalance($this->admin->company_id, Account::Company, 1000);
        $this->approveFloat($this->postJson('/api/v1/capital/floats', ['amount' => 1000, 'from_account' => Account::Company->value])->assertCreated()->json('data.id'));
        $transfer = FloatTransfer::firstOrFail();

        $foreign = FloatTransfer::create(['company_id' => Company::factory()->create()->id, 'type' => 'company_to_branch', 'amount' => 1, 'status' => 'approved', 'transfer_date' => today()]);
        $this->postJson("/api/v1/capital/floats/{$foreign->id}/reverse", ['reason' => 'Mistake'])->assertNotFound();

        $roles = $this->admin->company->roles()->pluck('id', 'key');
        $admin = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'role_id' => $roles['admin']]);
        $this->actingAs($admin)->getJson('/api/v1/capital/floats')->assertOk()->assertJsonPath('data.0.can_reverse', false);
        $this->actingAs($admin)->postJson("/api/v1/capital/floats/{$transfer->id}/reverse", ['reason' => 'Mistake'])->assertForbidden();

        $teller = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'role_id' => $roles['teller']]);
        $this->actingAs($teller)->postJson("/api/v1/capital/floats/{$transfer->id}/reverse", ['reason' => 'Mistake'])->assertForbidden();

        $finance = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'role_id' => $roles['finance']]);
        $this->actingAs($finance)->postJson("/api/v1/capital/floats/{$transfer->id}/reverse", ['reason' => 'Mistake'])->assertOk();
        $this->assertSame('reversed', $transfer->fresh()->status);
    }

    /**
     * Rule 6: a float requested by the signed-in admin is approved (posted) by a second authorised user.
     */
    private function approveFloat(int $id): void
    {
        $this->asApprover($this->admin, fn () => $this->postJson("/api/v1/capital/floats/{$id}/approve")->assertOk());
    }
}

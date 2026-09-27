<?php

namespace Tests\Feature\Api\Accounting;

use App\Enums\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FloatTransfer;
use App\Models\JournalEntry;
use App\Services\Ledger;
use App\Services\PeriodClose;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

class JournalApiTest extends TestCase
{
    use AccountingTestHelpers, RefreshDatabase;
    use UsesSecondApprover;

    public function test_journal_list_filters_by_date_branch_account_reference_and_source(): void
    {
        $admin = $this->signInAdmin();
        $other = Branch::factory()->create(['company_id' => $admin->company_id]);
        $this->postIncome($admin->company_id, $admin->branch_id, 10000, 1000, 0, 0, '2026-08-10');
        $this->postExpense($admin->company_id, $other->id, 3000, '2026-08-12');
        $this->postIncome($admin->company_id, $admin->branch_id, 5000, 0, 0, 0, '2026-07-01');

        $august = ['from' => '2026-08-01', 'to' => '2026-08-31'];
        $this->getJson('/api/v1/accounting/journal?'.http_build_query($august))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/accounting/journal?'.http_build_query($august + ['branch_id' => $other->id]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.description', 'Expenses: TEST')->assertJsonPath('data.0.total', 3000);
        $this->getJson('/api/v1/accounting/journal?'.http_build_query($august + ['account' => 'reserve']))->assertOk()->assertJsonCount(1, 'data');

        $entry = JournalEntry::where('description', 'Expenses: TEST')->firstOrFail();
        $this->getJson('/api/v1/accounting/journal?'.http_build_query($august + ['reference' => substr($entry->reference, -6)]))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/accounting/journal?'.http_build_query($august + ['source' => 'manual']))->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_entry_detail_shows_balanced_lines(): void
    {
        $admin = $this->signInAdmin();
        $this->postIncome($admin->company_id, $admin->branch_id, 10000, 1000, 2000, 0, '2026-08-10');
        $entry = JournalEntry::firstOrFail();

        $response = $this->getJson("/api/v1/accounting/journal/{$entry->id}")->assertOk();

        $lines = collect($response->json('data.lines'));
        $this->assertCount(5, $lines);
        $this->assertEquals($lines->sum('debit'), $lines->sum('credit'));
        $this->assertEquals(12000, $response->json('data.total'));
        $this->assertSame($admin->branch->name, $lines->firstWhere('key', 'reserve')['scope']);
    }

    public function test_reversal_requires_reason_posts_opposite_entry_today_and_is_audited(): void
    {
        $admin = $this->signInAdmin();
        $ledger = app(Ledger::class);
        $entry = $ledger->openingBalance($admin->company_id, Account::Principal, 80000, branch: $admin->branch_id, date: CarbonImmutable::parse('2026-08-01'));

        $this->postJson("/api/v1/accounting/journal/{$entry->id}/reverse", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        // Rule 6: the employee who posted the entry cannot reverse it.
        $this->postJson("/api/v1/accounting/journal/{$entry->id}/reverse", ['reason' => 'Posted to wrong branch'])->assertForbidden()
            ->assertJsonPath('message', 'You posted this transaction, so another authorised user must reverse it.');

        $poster = $admin;
        $admin = $this->secondApprover($poster);
        $this->actingAs($admin);
        $this->postJson("/api/v1/accounting/journal/{$entry->id}/reverse", ['reason' => 'Posted to wrong branch'])
            ->assertOk()->assertJsonPath('message', 'Transaction Reversed successfully');

        $reversal = JournalEntry::where('reversal_of_id', $entry->id)->firstOrFail();
        $this->assertSame(now()->toDateString(), $reversal->entry_date->toDateString());
        $this->assertSame('Posted to wrong branch', $reversal->reversal_reason);
        $this->assertSame($admin->id, $reversal->employee_id);
        $this->assertSame(0.0, $ledger->balance($admin->company_id, Account::Principal, $admin->branch_id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'JournalEntry.reversed', 'auditable_id' => $entry->id, 'employee_id' => $admin->id]);

        $this->getJson("/api/v1/accounting/journal/{$entry->id}")->assertJsonPath('data.is_reversed', true);
        $this->postJson("/api/v1/accounting/journal/{$entry->id}/reverse", ['reason' => 'Again'])->assertUnprocessable();
        $this->postJson("/api/v1/accounting/journal/{$reversal->id}/reverse", ['reason' => 'Undo reversal'])->assertUnprocessable();
    }

    public function test_entries_cannot_be_deleted_or_edited_through_the_api(): void
    {
        $admin = $this->signInAdmin();
        $entry = app(Ledger::class)->openingBalance($admin->company_id, Account::Company, 1000);

        $this->deleteJson("/api/v1/accounting/journal/{$entry->id}")->assertMethodNotAllowed();
        $this->putJson("/api/v1/accounting/journal/{$entry->id}", ['description' => 'X'])->assertMethodNotAllowed();
        $this->assertModelExists($entry);
    }

    public function test_reverse_permission_and_branch_scope(): void
    {
        $admin = $this->signInAdmin();
        $other = Branch::factory()->create(['company_id' => $admin->company_id]);
        $this->postExpense($admin->company_id, $other->id, 3000, '2026-08-12');
        $entry = JournalEntry::firstOrFail();
        $adminRole = $this->employeeWithRole($admin, 'admin');
        $this->actingAs($adminRole)->getJson("/api/v1/accounting/journal/{$entry->id}")->assertOk();
        $this->actingAs($adminRole)->postJson("/api/v1/accounting/journal/{$entry->id}/reverse", ['reason' => 'No right'])->assertForbidden();

        $manager = $this->employeeWithRole($admin, 'branch_manager', ['accounting.view', 'accounting.reverse']);
        $this->actingAs($manager)->getJson('/api/v1/accounting/journal?from=2026-08-01&to=2026-08-31')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($manager)->getJson("/api/v1/accounting/journal/{$entry->id}")->assertNotFound();
        $this->actingAs($manager)->postJson("/api/v1/accounting/journal/{$entry->id}/reverse", ['reason' => 'Other branch'])->assertNotFound();
        $this->assertSame(0, AuditLog::where('action', 'JournalEntry.reversed')->count());
    }

    public function test_capital_entries_are_hidden_without_capital_view(): void
    {
        $admin = $this->signInAdmin();
        app(Ledger::class)->openingBalance($admin->company_id, Account::Company, 1000000, date: CarbonImmutable::parse('2026-08-01'));
        $this->postExpense($admin->company_id, $admin->branch_id, 3000, '2026-08-12');

        $finance = $this->employeeWithRole($admin, 'finance');
        $this->actingAs($finance)->getJson('/api/v1/accounting/journal?from=2026-08-01&to=2026-08-31')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_entries_posted_by_a_module_cannot_be_reversed_from_the_journal(): void
    {
        $admin = $this->signInAdmin();
        $ledger = app(Ledger::class);
        $ledger->openingBalance($admin->company_id, Account::Company, 500000);
        $float = $this->postJson('/api/v1/capital/floats', ['amount' => 1000, 'from_account' => Account::Company->value])->assertCreated()->json('data.id');
        $this->approveAsSecondUser($admin, "/api/v1/capital/floats/{$float}/approve");
        $entry = JournalEntry::where('source_type', (new FloatTransfer)->getMorphClass())->firstOrFail();
        $entries = JournalEntry::count();

        $this->getJson("/api/v1/accounting/journal/{$entry->id}")->assertOk()
            ->assertJsonPath('data.can_reverse', false)
            ->assertJsonPath('data.reverse_blocked_reason', 'This entry was posted by Float Transfer. Reverse it from that module so its dependent records stay consistent.');

        $this->postJson("/api/v1/accounting/journal/{$entry->id}/reverse", ['reason' => 'Try generic reversal'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This entry was posted by Float Transfer. Reverse it from that module so its dependent records stay consistent.');

        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame(499000.0, $ledger->balance($admin->company_id, Account::Company));
        $this->assertSame(1000.0, $ledger->balance($admin->company_id, Account::Principal));
        $this->assertSame(0, AuditLog::where('action', 'JournalEntry.reversed')->count());
    }

    public function test_month_end_closing_entries_cannot_be_reversed(): void
    {
        $admin = $this->signInAdmin();
        $this->postIncome($admin->company_id, $admin->branch_id, 10000, 1000, 0, 0, '2026-08-10');
        $period = app(PeriodClose::class)->calculate($admin->company_id, CarbonImmutable::parse('2026-08-01'));
        app(PeriodClose::class)->close($period, $admin);
        $closing = JournalEntry::where('transaction_type', 'month_end_closing')->firstOrFail();
        $entries = JournalEntry::count();

        $this->postJson("/api/v1/accounting/journal/{$closing->id}/reverse", ['reason' => 'Reopen August'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason')
            ->assertJsonPath('message', 'Month-end closing entries cannot be reversed: reopening a closed accounting period is not supported.');
        $this->assertSame($entries, JournalEntry::count());
    }

    public function test_manual_reversal_is_blocked_when_the_money_has_already_been_used(): void
    {
        $admin = $this->signInAdmin();
        $ledger = app(Ledger::class);
        $entry = $ledger->openingBalance($admin->company_id, Account::Principal, 80000, branch: $admin->branch_id);
        $ledger->transfer($admin->company_id, ['account' => Account::Principal, 'branch' => $admin->branch_id], ['account' => Account::Company], 50000, 'MANUAL MOVE');

        $this->postJson("/api/v1/accounting/journal/{$entry->id}/reverse", ['reason' => 'Posted twice'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->assertFalse(JournalEntry::where('reversal_of_id', $entry->id)->exists());
        $this->assertSame(30000.0, $ledger->balance($admin->company_id, Account::Principal, $admin->branch_id));
    }
}

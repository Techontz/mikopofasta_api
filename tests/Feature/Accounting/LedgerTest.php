<?php

namespace Tests\Feature\Accounting;

use App\Enums\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unbalanced_entries_are_rejected(): void
    {
        $admin = $this->signInAdmin();

        $this->expectException(InvalidArgumentException::class);

        app(Ledger::class)->journal($admin->company_id, 'BROKEN', [
            ['account' => Account::Company, 'debit' => 100],
            ['account' => Account::Capital, 'credit' => 90],
        ]);
    }

    public function test_transfer_posts_equal_debits_and_credits_and_updates_balances(): void
    {
        $admin = $this->signInAdmin();
        $ledger = app(Ledger::class);

        $ledger->transfer($admin->company_id, ['account' => Account::Capital], ['account' => Account::Company], 1000000, 'CAPITAL');
        $ledger->transfer($admin->company_id, ['account' => Account::Company], ['account' => Account::Principal, 'branch' => $admin->branch_id], 400000, 'FLOAT');

        $this->assertSame((float) JournalLine::sum('debit'), (float) JournalLine::sum('credit'));
        $this->assertSame(1000000.0, $ledger->balance($admin->company_id, Account::Capital));
        $this->assertSame(600000.0, $ledger->balance($admin->company_id, Account::Company));
        $this->assertSame(400000.0, $ledger->balance($admin->company_id, Account::Principal, $admin->branch_id));
    }

    public function test_entries_cannot_be_edited_or_deleted_only_reversed(): void
    {
        $admin = $this->signInAdmin();
        $ledger = app(Ledger::class);
        $entry = $ledger->openingBalance($admin->company_id, Account::Company, 5000);

        try {
            $entry->delete();
            $this->fail('Deleting a journal entry must not be allowed.');
        } catch (LogicException) {
            $this->assertModelExists($entry);
        }

        $reversal = $ledger->reverse($entry, 'Posted in error');

        $this->assertSame($entry->id, $reversal->reversal_of_id);
        $this->assertSame(0.0, $ledger->balance($admin->company_id, Account::Company));
        $this->assertSame(2, JournalEntry::count());

        $this->expectException(InvalidArgumentException::class);
        $ledger->reverse($entry->fresh(), 'Twice');
    }
}

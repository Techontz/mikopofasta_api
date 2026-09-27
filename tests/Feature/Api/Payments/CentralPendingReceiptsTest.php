<?php

namespace Tests\Feature\Api\Payments;

use App\Enums\Account;
use App\Enums\TransactionType;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Services\Ledger;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Specification §11: ONE central Pending / Unverified Receipts account at HQ — no pending account per branch — while every
 * receipt keeps its branch, so HQ sees the total and each branch sees its own.
 */
class CentralPendingReceiptsTest extends TestCase
{
    use InteractsWithRepayments;
    use RefreshDatabase;

    public function test_unverified_receipts_sit_in_one_hq_account_and_keep_their_branch(): void
    {
        $admin = $this->signInAdmin();
        $second = Branch::factory()->create(['company_id' => $admin->company_id, 'name' => 'BRANCH B']);
        $teller = $this->employeeWithRole($admin, 'teller');
        $payments = app(PaymentService::class);

        // Branch A: teller cash not yet verified (in the ledger). Branch B: a mobile-money receipt awaiting Finance (no ledger).
        $cash = $payments->recordCash($this->activeLoan($admin), 50000, 'CASH', $teller);
        $mobile = $payments->recordBranchReceipt($this->activeLoan($admin, branch: $second), ['amount' => 30000, 'channel' => 'VODACOM', 'transaction_id' => 'MP-B-1'], $teller);

        $this->assertSame([$admin->branch_id, $second->id], [$cash->branch_id, $mobile->branch_id]);
        $this->assertSame(50000.0, $this->balance($admin, Account::Suspense, null), 'the central HQ account holds it');
        $this->assertSame([0.0, 0.0], [$this->balance($admin, Account::Suspense, $admin->branch_id), $this->balance($admin, Account::Suspense, $second->id)], 'no branch pending account');

        $all = $this->getJson('/api/v1/reports/financial/suspense?branch_id=all')->assertOk()->json('data');
        $this->assertEquals(80000, $all['unverified_total'], 'HQ total = branch A 50,000 + branch B 30,000');
        $this->assertEquals(30000, $all['awaiting_approval_total']);
        $this->assertEqualsCanonicalizing(
            [['branch' => $admin->branch->name, 'amount' => 50000], ['branch' => 'BRANCH B', 'amount' => 30000]],
            $all['unverified_by_branch'],
        );

        $branchA = $this->getJson("/api/v1/reports/financial/suspense?branch_id={$admin->branch_id}")->assertOk()->json('data');
        $this->assertEquals([50000, 50000, 0], [$branchA['unverified_total'], $branchA['ledger_balance'], $branchA['difference']]);

        $branchB = $this->getJson("/api/v1/reports/financial/suspense?branch_id={$second->id}")->assertOk()->json('data');
        $this->assertEquals([30000, 0], [$branchB['unverified_total'], $branchB['ledger_balance']]);
    }

    public function test_branch_level_balances_from_before_the_rule_move_to_hq_with_a_traceable_adjustment(): void
    {
        $admin = $this->signInAdmin();
        $ledger = app(Ledger::class);
        // A receipt recorded under the old rule, on the branch's own suspense account.
        $ledger->journal($admin->company_id, 'SUSPENSE LEGACY', [
            ['account' => Account::Bank, 'debit' => 70000],
            ['account' => Account::Suspense, 'branch' => $admin->branch_id, 'credit' => 70000],
        ], branch: $admin->branch_id);

        $this->artisan('mkopa:centralise-pending-receipts', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(70000.0, $this->balance($admin, Account::Suspense, $admin->branch_id), 'a dry run posts nothing');

        $this->artisan('mkopa:centralise-pending-receipts')->assertSuccessful();

        $this->assertSame(0.0, $this->balance($admin, Account::Suspense, $admin->branch_id));
        $this->assertSame(70000.0, $this->balance($admin, Account::Suspense, null));
        $entry = JournalEntry::where('transaction_type', TransactionType::Adjustment->value)->sole();
        $this->assertSame($admin->branch_id, $entry->branch_id, 'the adjustment is recorded for the branch it came from');
        $this->assertStringContainsString('§11', $entry->description);

        $this->artisan('mkopa:centralise-pending-receipts')->assertSuccessful();
        $this->assertSame(1, JournalEntry::where('transaction_type', TransactionType::Adjustment->value)->count(), 'running again changes nothing');

        $report = $this->getJson("/api/v1/reports/financial/suspense?branch_id={$admin->branch_id}")->assertOk()->json('data');
        $this->assertEquals(70000, $report['ledger_balance'], 'the branch keeps its share after centralisation');
    }
}

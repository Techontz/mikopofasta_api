<?php

namespace Tests\Feature\Api\Reports\Financial;

use App\Enums\Account;
use App\Enums\PaymentStatus;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\ExpenseRequest;
use App\Models\ExpenseType;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\TellerDeposit;
use App\Services\Ledger;
use App\Services\PeriodClose;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Accounting\AccountingTestHelpers;
use Tests\TestCase;

/**
 * HQ 2% Hold, Loss Carry Forward, Expense (tagging), Suspense and Reversal reports.
 */
class FinancialControlReportsTest extends TestCase
{
    use AccountingTestHelpers, RefreshDatabase;

    private Employee $admin;

    private Branch $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->second = Branch::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'SECOND']);
    }

    public function test_hq_hold_and_loss_carry_forward_from_month_end_results(): void
    {
        $company = $this->admin->company_id;
        $first = $this->admin->branch_id;
        $second = $this->second->id;
        // FIRST: profit 50,000 in June, July, August. SECOND: loss 30,000 (June), loss 10,000 (July), profit 60,000 (August).
        foreach (['2026-06-05', '2026-07-05', '2026-08-05'] as $date) {
            $this->postIncome($company, $first, 50000, 0, 0, 0, $date);
        }
        $this->postIncome($company, $second, 100000, 0, 0, 0, '2026-06-01');
        $this->postExpense($company, $second, 130000, '2026-06-02');
        $this->postIncome($company, $second, 10000, 0, 0, 0, '2026-07-01');
        $this->postExpense($company, $second, 20000, '2026-07-02');
        $this->postIncome($company, $second, 60000, 0, 0, 0, '2026-08-01');

        $periodClose = app(PeriodClose::class);
        $periodClose->close($periodClose->calculate($company, CarbonImmutable::parse('2026-06-01')), $this->admin);
        $periodClose->close($periodClose->calculate($company, CarbonImmutable::parse('2026-07-01')), $this->admin);
        $periodClose->calculate($company, CarbonImmutable::parse('2026-08-01'));

        $hold = $this->getJson('/api/v1/reports/financial/hq-hold?branch_id=all&from=2026-07-01&to=2026-08-31')->assertOk()->json('data');
        $this->assertCount(4, $hold['rows']);
        $firstJuly = collect($hold['rows'])->where('branch_id', (string) $first)->firstWhere('month', '2026-07');
        $this->assertEquals(1000, $firstJuly['hq_hold_amount']);
        $this->assertSame('CLOSED', $firstJuly['status']);
        $this->assertEquals(2000, $firstJuly['accumulated'], 'June hold counts in the accumulated reserve');
        $secondAugust = collect($hold['rows'])->where('branch_id', (string) $second)->firstWhere('month', '2026-08');
        $this->assertEquals(20000, $secondAugust['net_profit']);
        $this->assertEquals(400, $secondAugust['hq_hold_amount']);
        $this->assertSame('PROVISIONAL', $secondAugust['status']);
        $this->assertEquals(1000, $hold['total_held']);
        $this->assertEquals(1400, $hold['total_provisional']);
        $this->assertEquals(2000, $hold['total_accumulated']);
        $this->assertEquals(2000, app(Ledger::class)->balance($company, Account::RetainedProfit), 'HQ profit account holds the posted 2 %');

        $loss = $this->getJson("/api/v1/reports/financial/loss-carry-forward?branch_id={$second}&from=2026-06-01&to=2026-08-31")->assertOk()->json('data');
        $rows = collect($loss['rows'])->keyBy('month');
        $this->assertSame(['2026-06', '2026-07', '2026-08'], $rows->keys()->all());
        $this->assertEquals(30000, $rows['2026-06']['remaining_loss']);
        $this->assertSame(1, $rows['2026-06']['loss_streak']);
        $this->assertEquals(30000, $rows['2026-07']['previous_loss']);
        $this->assertEquals(10000, $rows['2026-07']['loss_added']);
        $this->assertEquals(40000, $rows['2026-07']['remaining_loss']);
        $this->assertSame(2, $rows['2026-07']['loss_streak']);
        $this->assertSame('Loss carried forward', $rows['2026-07']['blocked_reason']);
        $this->assertEquals(40000, $rows['2026-08']['loss_offset']);
        $this->assertEquals(0, $rows['2026-08']['remaining_loss']);
        $this->assertSame(0, $rows['2026-08']['loss_streak']);
        $this->assertTrue($rows['2026-08']['commission_eligible']);
        $this->assertSame(0, $loss['branches_in_loss']);

        $julyOnly = $this->getJson('/api/v1/reports/financial/loss-carry-forward?branch_id=all&from=2026-07-01&to=2026-07-31')->json('data');
        $this->assertSame(1, $julyOnly['branches_in_loss']);
        $this->assertEquals(40000, $julyOnly['total_remaining_loss']);
        $this->assertSame(2, collect($julyOnly['branches'])->firstWhere('branch_id', (string) $second)['loss_streak']);
    }

    public function test_expense_report_tags_branch_and_hq_expenses_and_detects_mis_tagging(): void
    {
        $company = $this->admin->company_id;
        $rent = ExpenseType::create(['company_id' => $company, 'scope' => 'branch', 'name' => 'RENT']);
        $fuel = ExpenseType::create(['company_id' => $company, 'scope' => 'hq', 'name' => 'FUEL']);
        $entry = app(Ledger::class)->transfer($company, ['account' => Account::Company], ['account' => Account::OperatingExpense], 1, 'Expenses: TEST');
        $base = ['company_id' => $company, 'status' => 'accepted', 'employee_id' => $this->admin->id, 'approved_by' => $this->admin->id, 'journal_entry_id' => $entry->id];

        ExpenseRequest::create(['scope' => 'branch', 'branch_id' => $this->admin->branch_id, 'expense_type_id' => $rent->id, 'amount' => 100000, 'paid_from_account' => 'petty_cash', 'request_date' => '2026-07-10', 'approved_at' => '2026-07-11 10:00:00'] + $base);
        ExpenseRequest::create(['scope' => 'hq', 'branch_id' => null, 'expense_type_id' => $fuel->id, 'amount' => 40000, 'paid_from_account' => 'company_cash', 'request_date' => '2026-07-12', 'approved_at' => '2026-07-12 10:00:00'] + $base);
        ExpenseRequest::create(['scope' => 'hq', 'branch_id' => $this->second->id, 'expense_type_id' => $fuel->id, 'amount' => 60000, 'paid_from_account' => 'company_cash', 'request_date' => '2026-08-02', 'approved_at' => '2026-08-02 10:00:00'] + $base);
        ExpenseRequest::create(['scope' => 'hq', 'branch_id' => null, 'expense_type_id' => $rent->id, 'amount' => 5000, 'paid_from_account' => 'petty_cash', 'request_date' => '2026-08-03', 'approved_at' => '2026-08-03 10:00:00', 'journal_entry_id' => null] + $base);
        ExpenseRequest::create(['scope' => 'branch', 'branch_id' => $this->second->id, 'expense_type_id' => $rent->id, 'amount' => 999, 'status' => 'pending', 'request_date' => '2026-08-03'] + $base);

        $data = $this->getJson('/api/v1/reports/financial/expenses?branch_id=all&from=2026-07-01&to=2026-08-31')->assertOk()->json('data');

        $this->assertCount(4, $data['rows']);
        $this->assertEquals(205000, $data['total']);
        $this->assertEquals(100000, $data['branch_total']);
        $this->assertEquals(45000, $data['hq_total']);
        $this->assertEquals(60000, $data['hq_paid_branch_tagged_total']);
        $this->assertSame(1, $data['mis_tagged_count']);
        $misTagged = collect($data['rows'])->firstWhere('mis_tagged', true);
        $this->assertEquals(5000, $misTagged['amount']);
        $this->assertContains('HQ expense paid from branch PETTY CASH A/C', $misTagged['flags']);
        $this->assertContains('Expense type registered for BRANCH', $misTagged['flags']);
        $this->assertContains('Not posted to ledger', $misTagged['flags']);
        $this->assertSame('PETTY CASH A/C', $misTagged['paid_from']);
        $this->assertEquals(105000, collect($data['by_category'])->firstWhere('label', 'RENT')['amount']);
        $months = collect($data['months'])->keyBy('month');
        $this->assertEquals(40000, $months['2026-07']['hq']);
        $this->assertEquals(65000, $months['2026-08']['hq']);
        $this->assertEquals(62.5, $months['2026-08']['hq_change_percent']);

        $second = $this->getJson("/api/v1/reports/financial/expenses?branch_id={$this->second->id}&from=2026-07-01&to=2026-08-31")->assertOk()->json('data');
        $this->assertSame(['HQ-PAID / BRANCH-TAGGED'], array_column($second['rows'], 'tag'));
        $hq = $this->getJson('/api/v1/reports/financial/expenses?branch_id=hq&from=2026-07-01&to=2026-08-31')->assertOk()->json('data');
        $this->assertEquals(105000, $hq['total']);
    }

    public function test_suspense_report_lists_unmatched_and_pending_money_with_aging_and_ledger_balance(): void
    {
        $company = $this->admin->company_id;
        $ledger = app(Ledger::class);
        $bank = BankAccount::query()->forceCreate(['company_id' => $company, 'name' => 'CRDB']);

        $unmatched = Payment::create(['company_id' => $company, 'branch_id' => $this->admin->branch_id, 'source' => 'webhook', 'channel' => 'vodacom', 'transaction_id' => 'T-OLD', 'amount' => 70000, 'allocated_amount' => 20000, 'status' => PaymentStatus::Unallocated, 'paid_on' => '2026-07-20']);
        $ledger->journal($company, 'SUSPENSE T-OLD', [['account' => Account::Bank, 'debit' => 70000], ['account' => Account::Suspense, 'branch' => $this->admin->branch_id, 'credit' => 70000]], $unmatched, CarbonImmutable::parse('2026-07-20'));
        $ledger->journal($company, 'SUSPENSE ALLOCATION', [['account' => Account::Suspense, 'branch' => $this->admin->branch_id, 'debit' => 20000], ['account' => Account::Bank, 'credit' => 20000]], $unmatched, CarbonImmutable::parse('2026-07-25'));
        $flagged = Payment::create(['company_id' => $company, 'branch_id' => $this->admin->branch_id, 'source' => 'webhook', 'channel' => 'bank', 'transaction_id' => 'T-NEW', 'amount' => 15000, 'status' => PaymentStatus::Flagged, 'flag_reason' => 'Wrong customer', 'paid_on' => '2026-08-28']);
        $ledger->journal($company, 'SUSPENSE T-NEW', [['account' => Account::Bank, 'debit' => 15000], ['account' => Account::Suspense, 'branch' => $this->admin->branch_id, 'credit' => 15000]], $flagged, CarbonImmutable::parse('2026-08-28'));
        $teller = Payment::create(['company_id' => $company, 'branch_id' => $this->second->id, 'source' => 'teller', 'channel' => 'cash', 'receipt_number' => 'R-1', 'amount' => 30000, 'status' => PaymentStatus::PendingVerification, 'paid_on' => '2026-08-30']);
        $ledger->journal($company, 'TELLER CASH', [['account' => Account::TellerCash, 'branch' => $this->second->id, 'debit' => 30000], ['account' => Account::Suspense, 'branch' => $this->second->id, 'credit' => 30000]], $teller, CarbonImmutable::parse('2026-08-30'));
        Payment::create(['company_id' => $company, 'branch_id' => $this->admin->branch_id, 'source' => 'webhook', 'channel' => 'bank', 'transaction_id' => 'T-DONE', 'amount' => 9000, 'allocated_amount' => 9000, 'status' => PaymentStatus::Allocated, 'paid_on' => '2026-08-01']);
        TellerDeposit::create(['company_id' => $company, 'branch_id' => $this->second->id, 'bank_account_id' => $bank->id, 'slip_number' => 'SLIP1', 'amount' => 30000, 'deposit_date' => '2026-08-31', 'status' => TellerDeposit::STATUS_MISMATCH, 'statement_amount' => 29000]);

        $data = $this->getJson('/api/v1/reports/financial/suspense?branch_id=all&to=2026-08-31')->assertOk()->json('data');

        $this->assertCount(3, $data['rows']);
        $old = collect($data['rows'])->firstWhere('reference', 'T-OLD');
        $this->assertEquals(50000, $old['unallocated']);
        $this->assertSame(42, $old['age_days']);
        $this->assertSame('31-60 days', $old['bucket']);
        $this->assertSame('UNMATCHED', $old['type']);
        $this->assertSame('PENDING ALLOCATION', collect($data['rows'])->firstWhere('receipt_number', 'R-1')['type']);
        $this->assertEquals(65000, $data['unmatched_total']);
        $this->assertEquals(30000, $data['pending_allocation_total']);
        $this->assertEquals(95000, $data['ledger_balance']);
        $this->assertEquals(0, $data['difference']);
        $this->assertEquals(45000, collect($data['aging'])->firstWhere('bucket', '0-7 days')['amount']);
        $this->assertCount(1, $data['deposits']);
        $this->assertSame('MISMATCH', $data['deposits'][0]['status']);

        $second = $this->getJson("/api/v1/reports/financial/suspense?branch_id={$this->second->id}&to=2026-08-31")->assertOk()->json('data');
        $this->assertCount(1, $second['rows']);
        $this->assertEquals(30000, $second['ledger_balance']);
    }

    public function test_reversal_report_lists_reversed_transactions_with_reasons(): void
    {
        $company = $this->admin->company_id;
        $this->postExpense($company, $this->admin->branch_id, 12000, now()->subDays(3)->toDateString());
        $this->postExpense($company, $this->second->id, 8000, now()->subDays(2)->toDateString());
        $original = JournalEntry::where('branch_id', $this->admin->branch_id)->firstOrFail();
        app(Ledger::class)->reverse($original, 'Wrong amount');
        app(Ledger::class)->reverse(JournalEntry::where('branch_id', $this->second->id)->whereNull('reversal_of_id')->firstOrFail(), 'Duplicate');

        $data = $this->getJson('/api/v1/reports/financial/reversals?branch_id=all&from='.now()->subWeek()->toDateString().'&to='.now()->toDateString())->assertOk()->json('data');

        $this->assertSame(2, $data['count']);
        $this->assertEquals(20000, $data['total']);
        $row = collect($data['rows'])->firstWhere('original_reference', $original->reference);
        $this->assertSame('Wrong amount', $row['reason']);
        $this->assertEquals(12000, $row['amount']);
        $this->assertSame($this->admin->full_name, $row['reversed_by']);
        $this->assertSame('Expenses: TEST', $row['description']);

        $scoped = $this->getJson("/api/v1/reports/financial/reversals?branch_id={$this->second->id}")->assertOk()->json('data');
        $this->assertSame(['Duplicate'], array_column($scoped['rows'], 'reason'));
    }

    public function test_control_reports_require_financial_report_permission_and_branch_scope(): void
    {
        $officerRole = $this->admin->company->roles()->where('key', 'teller')->firstOrFail();
        $teller = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'role_id' => $officerRole->id]);
        foreach (['expenses', 'hq-hold', 'loss-carry-forward', 'suspense', 'reversals'] as $report) {
            $this->actingAs($teller)->getJson("/api/v1/reports/financial/{$report}")->assertForbidden();
        }

        $finance = $this->employeeWithRole($this->admin, 'finance');
        $this->actingAs($finance)->getJson('/api/v1/reports/financial/suspense?branch_id=hq')->assertOk();

        $manager = $this->employeeWithRole($this->admin, 'branch_manager', ['reports.financial'], $this->second->id);
        $this->actingAs($manager)->getJson("/api/v1/reports/financial/expenses?branch_id={$this->admin->branch_id}")->assertForbidden();
        $this->actingAs($manager)->getJson('/api/v1/reports/financial/hq-hold?branch_id=hq')->assertForbidden();

        app(PeriodClose::class)->calculate($this->admin->company_id, CarbonImmutable::parse('2026-08-01'));
        $rows = $this->actingAs($manager)->getJson('/api/v1/reports/financial/hq-hold?branch_id=all&from=2026-08-01&to=2026-08-31')->assertOk()->json('data.rows');
        $this->assertSame([(string) $this->second->id], array_column($rows, 'branch_id'));
    }
}

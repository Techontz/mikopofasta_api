<?php

namespace Tests\Feature\Accounting;

use App\Enums\Account;
use App\Enums\TransactionType;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\CommissionAllocation;
use App\Models\Customer;
use App\Models\DividendAllocation;
use App\Models\DividendDeclaration;
use App\Models\Employee;
use App\Models\ExpenseRequest;
use App\Models\ExpenseType;
use App\Models\HrmSetting;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanCategory;
use App\Models\PayrollRun;
use App\Models\Penalty;
use App\Models\ShareHolder;
use App\Models\Zone;
use App\Services\Accounting\LedgerIntegrity;
use App\Services\CapitalContributions;
use App\Services\DividendService;
use App\Services\ExpenseApproval;
use App\Services\FloatService;
use App\Services\Hrm\CommissionEngine;
use App\Services\Hrm\CommissionPayments;
use App\Services\Hrm\PayrollEngine;
use App\Services\Ledger;
use App\Services\LoanService;
use App\Services\PeriodClose;
use App\Services\Reports\Financial\FinancialScope;
use App\Services\Reports\Financial\ProfitLossReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

/**
 * The whole profit chain through the real services (Fund Flow Specification §1–16; user decisions D1–D9):
 * capital → float → loans → repayments (reserve, accrued penalties, fee, insurance) → expense → month-end close →
 * commission allocation (zone manager carve-out) → dividend declaration (reinvestment to principal) → payment → payroll.
 */
class ProfitChainTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    private Branch $branchA;

    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00:00'));
        $this->admin = $this->signInAdmin();
        $this->admin->company->update(['reserve_percent' => 20, 'penalty_type' => 'fixed', 'penalty_value' => 2000]);
        HrmSetting::forCompany($this->admin->company_id)->update(['commission_pool_percent' => 10, 'zone_override_percent' => 5, 'staff_fund_percent' => 10]);

        $zone = Zone::create(['company_id' => $this->admin->company_id, 'name' => 'LAKE']);
        $this->branchA = $this->admin->branch;
        $this->branchA->update(['zone_id' => $zone->id, 'name' => 'BRANCH A']);
        $this->branchB = Branch::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'BRANCH B']);
    }

    public function test_full_profit_chain_from_capital_to_dividend_payment_and_payroll(): void
    {
        $companyId = $this->admin->company_id;
        $a = $this->branchA->id;
        $b = $this->branchB->id;
        $staff1 = $this->staff($this->branchA, 300000);
        $staff2 = $this->staff($this->branchA, 100000);
        $manager = $this->staff($this->branchA, 500000, 'zone_manager', ['zone_id' => $this->branchA->zone_id]);
        $staff3 = $this->staff($this->branchB, 200000);

        // 1. Capital and float.
        $alpha = $this->holder('ALPHA');
        $beta = $this->holder('BETA');
        app(CapitalContributions::class)->contribute($alpha, 6000000, 'CASH', null, $this->admin);
        app(CapitalContributions::class)->contribute($beta, 4000000, 'CASH', null, $this->admin);
        // HQ runs the loan book for every branch, so the company funds the HQ PRINCIPAL A/C (no branch), never a branch.
        $float = app(FloatService::class);
        $float->approve($float->requestCompanyToHq($companyId, Account::Company, null, 5000000, $this->admin));
        $this->assertBalance(10000000, Account::Capital);
        $this->assertBalance(5000000, Account::Company);

        // 2. Loans disbursed (fee 5,000 deducted, insurance 1,000) — both out of the one HQ PRINCIPAL A/C.
        $loanA = $this->loan($this->branchA, 1000000);
        $loanB = $this->loan($this->branchB, 500000);
        $this->assertBalance(3500000, Account::Principal, message: 'HQ paid both loans: 5,000,000 − 1,000,000 − 500,000');
        $this->assertBalance(0, Account::Principal, $a, 'a branch holds no lending money');
        $this->assertBalance(0, Account::Principal, $b);
        $this->assertBalance(5000, Account::LoanFee, $a);

        // 3. Overdue job charges penalties without any journal (rule 14, cash basis).
        $this->travelTo(CarbonImmutable::parse('2026-07-10 09:00:00'));
        $loans = app(LoanService::class);
        $summary = $loans->applyPenaltiesAndDefaults(CarbonImmutable::today());
        $this->assertSame(2, $summary['penalties']);
        $penaltyA = Penalty::where('loan_id', $loanA->id)->sole();
        $this->assertNull($penaltyA->accrual_journal_entry_id, 'rule 14: no accrual journal');
        $this->assertSame(0, JournalEntry::where('transaction_type', TransactionType::PenaltyAccrual->value)->count());
        $this->assertBalance(0, Account::PenaltyReceivable, $a);
        $this->assertBalance(0, Account::PenaltyIncome, $a, 'rule 14: an unpaid penalty is not income');

        // 4. Repayments: principal → penalty (cash: Cr penalty income) → interest (20% reserve to INTEREST RESERVE) → insurance
        //    (rule 15: Cr INSURANCE RESERVE at collection).
        $loans->deposit($loanA->fresh(), 1303000, CarbonImmutable::today(), 'CASH', $this->admin);
        $loans->deposit($loanB->fresh(), 653000, CarbonImmutable::today(), 'CASH', $this->admin);
        $this->assertBalance(0, Account::LoanReceivable, $a);
        $this->assertBalance(0, Account::PenaltyReceivable, $a);
        $this->assertBalance(2000, Account::PenaltyIncome, $a, 'rule 14: penalty income recognised when the cash is collected');
        $this->assertBalance(2000, Account::Penalty, $a);
        $this->assertBalance(240000, Account::InterestIncome, $a);
        $this->assertBalance(240000, Account::Interest, $a);
        $this->assertBalance(60000, Account::Reserve, $a);
        $this->assertBalance(60000, Account::InterestReserve, $a);
        $this->assertBalance(0, Account::InsuranceIncome, $a, 'rule 15: insurance collected is never income');
        $this->assertBalance(1000, Account::InsuranceReserve, $a, 'rule 15: insurance collected goes to INSURANCE RESERVE');
        $this->assertBalance(1000, Account::Insurance, $a);

        // 5. Branch expense paid from the branch PETTY CASH A/C (petty cash HQ sent out of interest income).
        app(Ledger::class)->transfer($companyId, ['account' => Account::Interest, 'branch' => $b], ['account' => Account::PettyCash, 'branch' => $b], 20000, 'PETTY CASH');
        $type = ExpenseType::create(['company_id' => $companyId, 'scope' => 'branch', 'name' => 'RENT']);
        $expense = ExpenseRequest::create(['company_id' => $companyId, 'scope' => 'branch', 'branch_id' => $b, 'expense_type_id' => $type->id, 'amount' => 20000, 'description' => 'rent', 'status' => 'pending', 'request_date' => today()]);
        app(ExpenseApproval::class)->accept($expense, $this->admin, 20000, null);
        $this->assertBalance(100000, Account::Interest, $b);
        $this->assertBalance(0, Account::PettyCash, $b, 'the petty cash HQ sent paid the rent');

        // 6. Month-end close of July.
        $this->travelTo(CarbonImmutable::parse('2026-08-02 09:00:00'));
        $close = app(PeriodClose::class);
        $period = $close->close($close->calculate($companyId, CarbonImmutable::parse('2026-07-01')), $this->admin);
        $resultA = $period->results->firstWhere('branch_id', $a);
        $resultB = $period->results->firstWhere('branch_id', $b);
        $this->assertEquals([240000, 60000, 5000, 2000, 247000, 0, 247000, 4940, 242060], [$resultA->interest_income, $resultA->reserve_amount, $resultA->fee_income, $resultA->penalty_income, $resultA->total_income, $resultA->expenses, $resultA->gross_profit, $resultA->hq_hold_amount, $resultA->distributable_profit]);
        $this->assertEquals([120000, 30000, 127000, 20000, 107000, 2140, 104860], [$resultB->interest_income, $resultB->reserve_amount, $resultB->total_income, $resultB->expenses, $resultB->gross_profit, $resultB->hq_hold_amount, $resultB->distributable_profit]);
        $this->assertBalance(242060, Account::RetainedProfit, $a);
        $this->assertBalance(104860, Account::RetainedProfit, $b);
        $this->assertBalance(7080, Account::RetainedProfit);
        $this->assertBalance(1000, Account::InsuranceReserve, $a, 'D7: insurance income is not distributable profit');
        $this->assertBalance(0, Account::InsuranceIncome, $a);
        $this->assertBalance(90000, Account::InterestReserve, allBranches: true);

        $scope = new FinancialScope($companyId, null, true, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'));
        $consolidated = app(ProfitLossReport::class)->consolidated($scope);
        $this->assertEquals(354000, $consolidated['net_profit'], 'consolidated net profit = Σ branch gross profit');
        $this->assertEquals(2000, $consolidated['insurance_income']['amount']);
        $this->assertEquals(354000, $close->companyResult($companyId, CarbonImmutable::parse('2026-07-01'))['gross_profit']);

        // 7. Commission: 10% of distributable profit; the zone manager's 5% is carved out of branch A's pool. Branch B's zone has no
        //    zone manager (C4): its staff get 95% (9,961.70) and the 5% (524.30) is returned to profit.
        $commission = app(CommissionEngine::class);
        $rows = $commission->calculate($companyId, CarbonImmutable::parse('2026-07-01'), $this->admin);
        $this->assertEquals(34167.70, round((float) $rows->sum('amount'), 2));
        $this->assertEquals(1210.30, CommissionAllocation::where('employee_id', $manager->id)->value('amount'));
        $this->assertEquals(22995.70, round((float) CommissionAllocation::whereIn('employee_id', [$staff1->id, $staff2->id])->sum('amount'), 2));
        $this->assertEquals(9961.70, CommissionAllocation::where('employee_id', $staff3->id)->value('amount'));
        $this->assertSame(0, CommissionAllocation::whereNull('journal_entry_id')->where('amount', '>', 0)->count());
        $this->assertBalance(34167.70, Account::CommissionPayable, allBranches: true);
        $this->assertBalance(217854, Account::RetainedProfit, $a);
        $this->assertBalance(94898.30, Account::RetainedProfit, $b, 'C4: the 5% without a zone manager stays in profit');
        $this->assertBalance(0, Account::CommissionExpense, allBranches: true, message: 'D1: commission is not an expense');
        $report = $commission->report($companyId, CarbonImmutable::parse('2026-07-01'));
        $this->assertSame(CommissionEngine::STATUS_ALLOCATED, $report['allocation_status']);
        $this->assertSame(CommissionEngine::RULE_PROFIT_ALLOCATION, $report['rule']);
        $this->assertEquals(34167.70, $report['total_commission']);
        $this->assertEquals([524.30, 524.30, 0], [$report['total_returned_to_profit'], $report['total_returned_no_zone_manager'], $report['total_returned_no_staff']]);
        $this->assertCount(2, $report['journal_references']);
        $this->assertEquals(1210.30, collect($report['branches'])->firstWhere('branch_id', $a)['zone_manager_amount']);
        $this->assertEquals(524.30, collect($report['branches'])->firstWhere('branch_id', $b)['returned_no_zone_manager_amount']);

        // 8. Dividend declaration (requested, then approved by a second user): base = distributable − commission; 30% pool, 70%
        //    reinvested into the HQ PRINCIPAL A/C out of each branch's income pools.
        $this->establish([[$alpha, 600], [$beta, 400]]);
        $preview = app(DividendService::class)->preview($companyId, CarbonImmutable::parse('2026-07-01'));
        $this->assertTrue($preview['can_declare'], (string) $preview['blocking_reason']);
        $this->assertEquals([346920, 34167.70, 312752.30, 93825.69, 218926.61], [$preview['distributable_profit'], $preview['commission_amount'], $preview['base_amount'], $preview['dividend_pool'], $preview['reinvestment_amount']]);

        $requestId = $this->postJson('/api/v1/capital/dividends', ['period' => '2026-07'])->assertCreated()->json('data.id');
        $this->assertSame(0, DividendDeclaration::count(), 'C1: a pending request posts nothing');
        $this->approveAsSecondUser($this->admin, "/api/v1/capital/dividends/requests/{$requestId}/approve");
        $declaration = DividendDeclaration::sole();
        $this->assertSame('profit_allocation', $declaration->allocation_rule);
        $this->assertEquals([312752.30, 34167.70, 346920], [$declaration->base_amount, $declaration->commission_amount, $declaration->distributable_profit]);
        $this->assertBalance(0, Account::RetainedProfit, $a, 'the branch profit is fully allocated');
        $this->assertBalance(0, Account::RetainedProfit, $b);
        $this->assertBalance(93825.69, Account::DividendPayable);
        $this->assertBalance(218926.61, Account::ReinvestedProfit);
        $this->assertBalance(10000000, Account::Capital, message: 'D4: capital unchanged');
        $this->assertBalance(5218926.61, Account::Principal, message: 'both branches reinvested into the one HQ PRINCIPAL A/C: 5,000,000 + 218,926.61');
        $this->assertBalance(87502.20, Account::Interest, $a);
        $this->assertBalance(33571.19, Account::Interest, $b);
        $this->assertSame(TransactionType::ProfitReinvestment, JournalEntry::findOrFail($declaration->reinvestment_journal_entry_id)->transaction_type);
        $this->assertSame(['56295.41', '37530.28'], DividendAllocation::orderBy('id')->pluck('amount')->all());

        // No double distribution.
        $this->postJson('/api/v1/capital/dividends', ['period' => '2026-07'])->assertUnprocessable();
        $this->postJson('/api/v1/hrm/commission/calculate', ['period' => '2026-07'])->assertUnprocessable()
            ->assertJsonPath('errors.period.0', CommissionEngine::LOCKED_BY_DIVIDEND_MESSAGE);
        $this->assertSame(CommissionEngine::STATUS_LOCKED_BY_DIVIDEND, $commission->report($companyId, CarbonImmutable::parse('2026-07-01'))['allocation_status']);
        $this->assertStringContainsString('already been distributed', (string) app(ExpenseApproval::class)->reverseBlockedReason($expense->fresh()));

        // 9. Dividend payment from the company account.
        $allocation = DividendAllocation::where('share_holder_id', $alpha->id)->sole();
        app(DividendService::class)->pay($allocation, '56295.41', 'CASH', null, null, $this->admin);
        $this->assertBalance(37530.28, Account::DividendPayable);
        $this->assertBalance(4943704.59, Account::Company);

        // 10. Payroll no longer carries commission (spec §21 / §22); the commission payment flow clears COMMISSION PAYABLE from
        //     the branch INTEREST A/Cs, and the dividend figures of the month do not move.
        $payroll = app(PayrollEngine::class);
        $run = $payroll->generate($companyId, CarbonImmutable::parse('2026-07-01'), $this->admin);
        $this->assertEquals(0, round((float) $run->items()->sum('commission'), 2));
        $payroll->approve($run, $this->admin);
        $this->assertSame(PayrollRun::STATUS_APPROVED, $run->fresh()->status);
        $this->assertBalance(34167.70, Account::CommissionPayable, allBranches: true);
        $available = app(DividendService::class)->availableProfit($companyId, CarbonImmutable::parse('2026-07-01'));

        $payments = app(CommissionPayments::class);
        $july = fn () => $payments->select($companyId, CarbonImmutable::parse('2026-07-01'));
        $payments->request($july(), $this->admin);
        $payments->approve($july(), $this->admin);
        $payments->pay($july(), $this->admin, CarbonImmutable::today(), CommissionPayments::PAYING_INTEREST);
        $this->assertSame([CommissionAllocation::STATUS_PAID], CommissionAllocation::where('amount', '>', 0)->distinct()->pluck('payment_status')->all());
        $this->assertBalance(0, Account::CommissionPayable, allBranches: true);
        $this->assertBalance(0, Account::CommissionExpense, allBranches: true);
        $this->assertBalance(87502.20 - 24206, Account::Interest, $a);
        $this->assertBalance(33571.19 - 9961.70, Account::Interest, $b);
        $this->assertSame($available, app(DividendService::class)->availableProfit($companyId, CarbonImmutable::parse('2026-07-01')));
        $this->assertSame(CommissionEngine::STATUS_LOCKED_BY_DIVIDEND, $commission->report($companyId, CarbonImmutable::parse('2026-07-01'))['allocation_status']);

        // 11. Integrity: every check passes.
        $integrity = app(LedgerIntegrity::class)->run($companyId);
        $checks = collect($integrity['checks'])->keyBy('key');
        $this->assertSame('pass', $integrity['status'], json_encode($checks->whereNotIn('status', ['pass', 'info'])->values()));
        $this->assertSame(1, count($checks['profit_distribution']['details']['periods']));
        $this->assertEquals(218926.61, $checks['reinvested_profit']['details']['ledger']);
        $this->assertEquals(90000, $checks['interest_reserve']['details']['ledger']);
    }

    public function test_a_journal_failure_mid_declaration_rolls_everything_back(): void
    {
        [$period] = $this->closedMonthWithProfit(100000);
        $entries = JournalEntry::count();

        $this->partialMock(Ledger::class, function (MockInterface $mock): void {
            $mock->shouldReceive('journal')->once()->passthru();
            $mock->shouldReceive('journal')->andThrow(new RuntimeException('Ledger unavailable'));
        });

        $request = app(DividendService::class)->declare($this->admin->company_id, CarbonImmutable::parse($period->period_start->toDateString()), $this->admin);

        try {
            app(DividendService::class)->approveDeclaration($request, $this->secondApprover($this->admin));
            $this->fail('The ledger failure should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Ledger unavailable', $exception->getMessage());
        }

        $this->assertSame(0, DividendDeclaration::count());
        $this->assertSame(0, DividendAllocation::count());
        $this->assertSame($entries, JournalEntry::count());
        $this->assertTrue($request->fresh()->isPending(), 'the failed approval leaves the request pending');
    }

    public function test_reinvestment_shortfall_blocks_the_declaration_and_posts_nothing(): void
    {
        [$period] = $this->closedMonthWithProfit(100000, interestCash: 60000);
        $entries = JournalEntry::count();
        $month = CarbonImmutable::parse($period->period_start->toDateString());

        $preview = app(DividendService::class)->preview($this->admin->company_id, $month);
        $this->assertFalse($preview['can_declare']);
        $this->assertStringContainsString('BRANCH A needs TZS 70,000.00', (string) $preview['blocking_reason']);
        $this->assertStringContainsString('shortfall TZS 10,000.00', (string) $preview['blocking_reason']);

        $this->postJson('/api/v1/capital/dividends', ['period' => $month->format('Y-m')])->assertUnprocessable()
            ->assertJsonPath('errors.period.0', $preview['blocking_reason']);
        $this->assertSame(0, DividendDeclaration::count());
        $this->assertSame($entries, JournalEntry::count());
    }

    public function test_branch_split_is_cent_exact_and_uses_interest_then_fee_then_penalty(): void
    {
        [$period] = $this->closedMonthWithProfit(100000.03, interestCash: 20000, feeCash: 30000, penaltyCash: 50000.03);
        $ledger = app(Ledger::class);
        $ledger->journal($this->admin->company_id, 'PROFIT B', [
            ['account' => Account::Interest, 'branch' => $this->branchB->id, 'debit' => 50000.01],
            ['account' => Account::RetainedProfit, 'branch' => $this->branchB->id, 'credit' => 50000.01],
        ]);
        $period->results()->create(['branch_id' => $this->branchB->id, 'gross_profit' => 50000.01, 'net_profit' => 50000.01, 'distributable_profit' => 50000.01, 'commission_eligible' => true]);

        $request = app(DividendService::class)->declare($this->admin->company_id, CarbonImmutable::parse($period->period_start->toDateString()), $this->admin);
        $declaration = app(DividendService::class)->approveDeclaration($request, $this->secondApprover($this->admin));

        $this->assertSame('150000.04', $declaration->base_amount);
        $this->assertSame('45000.01', $declaration->dividend_amount);
        $this->assertSame('105000.03', $declaration->reinvest_amount);
        $lines = JournalEntry::with('lines.account')->findOrFail($declaration->reinvestment_journal_entry_id)->lines;
        $principal = $lines->filter(fn ($line): bool => $line->account->key === Account::Principal);
        $this->assertSame('105000.03', number_format((float) $principal->sum('debit'), 2, '.', ''));
        $fromA = $lines->filter(fn ($line): bool => $line->account->branch_id === $this->branchA->id && (float) $line->credit > 0)
            ->mapWithKeys(fn ($line): array => [$line->account->key->value => (float) $line->credit])->all();
        $this->assertSame(['interest' => 20000.0, 'loan_fee' => 30000.0, 'penalty' => 20000.02], $fromA);
        $retained = JournalEntry::with('lines.account')->findOrFail($declaration->journal_entry_id)->lines->filter(fn ($line): bool => $line->account->key === Account::RetainedProfit);
        $this->assertSame('150000.04', number_format((float) $retained->sum('debit'), 2, '.', ''));
    }

    public function test_declarations_need_a_closed_month(): void
    {
        $this->establish([[$this->holder('ALPHA'), 100]]);
        app(Ledger::class)->journal($this->admin->company_id, 'PROFIT', [
            ['account' => Account::Interest, 'branch' => $this->branchA->id, 'debit' => 1000],
            ['account' => Account::RetainedProfit, 'branch' => $this->branchA->id, 'credit' => 1000],
        ]);

        $this->getJson('/api/v1/capital/dividends/preview?period=2026-06')->assertOk()
            ->assertJsonPath('data.can_declare', false)
            ->assertJsonPath('data.blocking_reason', 'June 2026 is not closed. Dividends can only be declared for a closed accounting period.')
            ->assertJsonPath('data.profit_available', 1000);
        $this->postJson('/api/v1/capital/dividends', ['period' => '2026-06'])->assertUnprocessable()
            ->assertJsonPath('errors.period.0', 'June 2026 is not closed. Dividends can only be declared for a closed accounting period.');
    }

    public function test_mixed_legacy_and_new_repayment_entries_give_the_same_interest_and_reserve_and_reversals_net_out(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-15 09:00:00'));
        $companyId = $this->admin->company_id;
        $a = $this->branchA->id;
        $ledger = app(Ledger::class);
        $ledger->openingBalance($companyId, Account::Principal, 1000000, 'FLOAT');

        // Legacy shape: full interest to income, reserve fund line, no INTEREST RESERVE line.
        $legacy = $ledger->journal($companyId, 'LOAN RETURN LEGACY', [
            ['account' => Account::Interest, 'branch' => $a, 'debit' => 40000],
            ['account' => Account::Reserve, 'branch' => $a, 'debit' => 10000],
            ['account' => Account::InterestIncome, 'branch' => $a, 'credit' => 50000],
        ]);
        $loan = $this->loan($this->branchA, 100000);
        $transaction = app(LoanService::class)->deposit($loan->fresh(), 131000, CarbonImmutable::today(), 'CASH', $this->admin);
        $this->assertEquals(6000, $transaction->reserve);

        $close = app(PeriodClose::class);
        $row = $close->calculate($companyId, CarbonImmutable::parse('2026-07-01'))->results->firstWhere('branch_id', $a);
        $this->assertEquals(40000 + 24000, $row->interest_income);
        $this->assertEquals(10000 + 6000, $row->reserve_amount);
        $pnl = collect(app(ProfitLossReport::class)->branchPnl(new FinancialScope($companyId, null, true, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31')), [$a => 'A'])['rows'])->firstWhere('branch_id', (string) $a);
        $this->assertEquals([64000, 16000], [$pnl['interest_income'], $pnl['reserve_amount']]);

        $ledger->reverse($legacy, 'test');
        $ledger->reverse(JournalEntry::findOrFail($transaction->journal_entry_id), 'test');
        $row = $close->calculate($companyId, CarbonImmutable::parse('2026-07-01'))->results->firstWhere('branch_id', $a);
        $this->assertEquals([0, 0], [(float) $row->interest_income, (float) $row->reserve_amount]);
        $this->assertBalance(0, Account::InterestReserve, $a);
    }

    public function test_closing_moves_legacy_reserve_to_interest_reserve_and_insurance_to_insurance_reserve(): void
    {
        $companyId = $this->admin->company_id;
        $a = $this->branchA->id;
        app(Ledger::class)->journal($companyId, 'LOAN RETURN LEGACY', [
            ['account' => Account::Interest, 'branch' => $a, 'debit' => 40000],
            ['account' => Account::Reserve, 'branch' => $a, 'debit' => 10000],
            ['account' => Account::InterestIncome, 'branch' => $a, 'credit' => 50000],
            ['account' => Account::Insurance, 'branch' => $a, 'debit' => 3000],
            ['account' => Account::InsuranceIncome, 'branch' => $a, 'credit' => 3000],
        ], null, CarbonImmutable::parse('2026-07-01'));

        $this->travelTo(CarbonImmutable::parse('2026-08-02 09:00:00'));
        $close = app(PeriodClose::class);
        $close->close($close->calculate($companyId, CarbonImmutable::parse('2026-07-01')), $this->admin);

        $this->assertBalance(10000, Account::InterestReserve, $a);
        $this->assertBalance(3000, Account::InsuranceReserve, $a);
        $this->assertBalance(39200, Account::RetainedProfit, $a);
        $this->assertBalance(800, Account::RetainedProfit);
        $this->assertIntegrityPasses();
    }

    public function test_charging_a_penalty_posts_no_journal_even_for_a_closed_period_date(): void
    {
        $loan = $this->loan($this->branchA, 100000);
        AccountingPeriod::create(['company_id' => $this->admin->company_id, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'status' => AccountingPeriod::STATUS_CLOSED, 'closed_at' => now()]);
        $entries = JournalEntry::count();

        $closed = app(LoanService::class)->chargePenalty($loan, 1500, CarbonImmutable::parse('2026-06-20'));
        $open = app(LoanService::class)->chargePenalty($loan, 700, CarbonImmutable::parse('2026-07-01'));

        $this->assertSame($entries, JournalEntry::count(), 'rule 14: charging a penalty is not a money movement');
        $this->assertNull($closed->accrual_journal_entry_id);
        $this->assertNull($open->accrual_journal_entry_id);
        $this->assertSame('2026-06-20', $closed->penalty_date->toDateString());
        $this->assertBalance(0, Account::PenaltyReceivable, $this->branchA->id);
        $this->assertBalance(0, Account::PenaltyIncome, $this->branchA->id);
    }

    public function test_cash_basis_and_legacy_accrued_penalties_are_paid_waived_and_repaid_on_the_right_accounts(): void
    {
        $a = $this->branchA->id;
        $loans = app(LoanService::class);
        $loan = $this->loan($this->branchA, 100000);
        $cash = Penalty::create(['company_id' => $loan->company_id, 'branch_id' => $a, 'customer_id' => $loan->customer_id, 'loan_id' => $loan->id, 'amount' => 3000, 'penalty_date' => '2026-06-25']);
        $accrued = $this->legacyAccruedPenalty($loan, 5000, '2026-06-30');
        $this->assertBalance(5000, Account::PenaltyIncome, $a);

        // Mixed repayment: principal 100,000, then penalties oldest first: cash-basis 3,000 (income) + legacy accrued 2,000 (receivable).
        $transaction = $loans->deposit($loan->fresh(), 105000, CarbonImmutable::today(), 'CASH', $this->admin);
        $this->assertEquals(5000, $transaction->penalty);
        $this->assertBalance(8000, Account::PenaltyIncome, $a);
        $this->assertBalance(3000, Account::PenaltyReceivable, $a);
        $this->assertBalance(5000, Account::Penalty, $a);

        // Reversing the repayment restores the receivable and the penalty balances.
        $loans->reverseRepayment($transaction->fresh(), 'Wrong loan', $this->secondApprover($this->admin));
        $this->assertBalance(5000, Account::PenaltyReceivable, $a);
        $this->assertBalance(5000, Account::PenaltyIncome, $a);
        $this->assertEquals(0, $cash->fresh()->paid_amount);

        // Direct payments.
        $this->postJson("/api/v1/penalties/{$accrued->id}/pay", ['penart_paid' => 1000])->assertOk();
        $this->postJson("/api/v1/penalties/{$cash->id}/pay", ['penart_paid' => 1000])->assertOk();
        $this->assertBalance(4000, Account::PenaltyReceivable, $a);
        $this->assertBalance(6000, Account::PenaltyIncome, $a);
        $this->getJson('/api/v1/penalties')->assertOk()
            ->assertJsonPath('data.0.accounting', 'cash')
            ->assertJsonPath('data.1.accounting', 'accrued');

        // Waivers: a legacy accrued penalty reverses its unpaid 4,000 of income; a cash-basis penalty posts nothing (rule 14).
        $this->postJson("/api/v1/penalties/{$accrued->id}/waive")->assertOk();
        $this->assertNotNull($accrued->fresh()->waiver_journal_entry_id);
        $this->assertBalance(0, Account::PenaltyReceivable, $a);
        $this->assertBalance(2000, Account::PenaltyIncome, $a);
        $this->postJson("/api/v1/penalties/{$cash->id}/waive")->assertOk();
        $this->assertNull($cash->fresh()->waiver_journal_entry_id);
        $this->assertBalance(2000, Account::PenaltyIncome, $a);
        $this->postJson("/api/v1/penalties/{$cash->id}/waive")->assertUnprocessable();

        $this->assertIntegrityPasses();
    }

    public function test_write_off_also_writes_off_unpaid_legacy_accrued_penalties_only(): void
    {
        $a = $this->branchA->id;
        $loans = app(LoanService::class);
        $loan = $this->loan($this->branchA, 100000);
        $this->legacyAccruedPenalty($loan, 4000, today()->toDateString());
        Penalty::create(['company_id' => $loan->company_id, 'branch_id' => $a, 'customer_id' => $loan->customer_id, 'loan_id' => $loan->id, 'amount' => 9000, 'penalty_date' => today()]);

        $loans->writeOff($loan->fresh(), $this->admin);

        $this->assertBalance(0, Account::PenaltyReceivable, $a);
        $this->assertBalance(104000, Account::WriteOffExpense, $a);
        $this->assertBalance(0, Account::LoanReceivable, $a);
        $this->assertIntegrityPasses();
    }

    public function test_payroll_uses_expense_for_legacy_allocations_without_a_journal(): void
    {
        $staff = $this->staff($this->branchA, 100000);
        $month = CarbonImmutable::parse('2026-06-01');
        $period = AccountingPeriod::create(['company_id' => $this->admin->company_id, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'status' => AccountingPeriod::STATUS_CLOSED, 'closed_at' => now()]);
        $period->results()->create(['branch_id' => $this->branchA->id, 'net_profit' => 10000, 'distributable_profit' => 10000, 'commission_eligible' => true]);
        $run = PayrollRun::create(['company_id' => $this->admin->company_id, 'period' => '2026-06-01', 'status' => PayrollRun::STATUS_DRAFT]);
        CommissionAllocation::create(['company_id' => $this->admin->company_id, 'accounting_period_id' => $period->id, 'branch_id' => $this->branchA->id, 'employee_id' => $staff->id, 'kind' => CommissionAllocation::KIND_BRANCH_STAFF, 'distributable_profit' => 10000, 'pool_percent' => 10, 'pool_amount' => 1000, 'base_salary' => 100000, 'total_salary' => 100000, 'share_percent' => 100, 'amount' => 1000, 'payroll_run_id' => $run->id, 'payment_status' => CommissionAllocation::STATUS_PAYROLL]);
        // A legacy draft generated before commission got its own payment flow: the line still carries the commission.
        $line = app(PayrollEngine::class)->preview($this->admin->company_id, $month)->map(fn (array $line): array => collect($line)->except(['employee', 'branch', 'allowance_ids', 'negligence_outstanding', 'net_commission'])->all())->first();
        $run->items()->create(['commission' => 1000, 'gross' => $line['gross'] + 1000, 'take_home' => $line['take_home'] + 1000] + $line);

        $report = app(CommissionEngine::class)->report($this->admin->company_id, $month);
        $this->assertSame(CommissionEngine::RULE_LEGACY, $report['rule']);
        $this->assertSame([], $report['journal_references']);

        app(PayrollEngine::class)->approve($run, $this->admin);
        $this->assertBalance(1000, Account::CommissionExpense, $this->branchA->id);
        $this->assertBalance(0, Account::CommissionPayable, allBranches: true);
    }

    public function test_recalculation_reverses_the_previous_allocation_journals(): void
    {
        $this->staff($this->branchA, 100000);
        [$period] = $this->closedMonthWithProfit(50000);
        $month = CarbonImmutable::parse($period->period_start->toDateString());
        $engine = app(CommissionEngine::class);

        $engine->calculate($this->admin->company_id, $month, $this->admin);
        $first = CommissionAllocation::sole()->journal_entry_id;
        $engine->calculate($this->admin->company_id, $month, $this->admin);

        $this->assertTrue(JournalEntry::where('reversal_of_id', $first)->exists());
        $this->assertNotSame($first, CommissionAllocation::sole()->journal_entry_id);
        $this->assertBalance(4750, Account::CommissionPayable, allBranches: true, message: 'C4: staff 95% without a zone manager');
        $this->assertBalance(45250, Account::RetainedProfit, $this->branchA->id);
        $this->assertCount(1, $engine->allocationJournals($period));
    }

    public function test_permissions_and_company_isolation_of_the_changed_endpoints(): void
    {
        $loan = $this->loan($this->branchA, 100000);
        $penalty = app(LoanService::class)->chargePenalty($loan, 1000, CarbonImmutable::today());

        $officer = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branchA->id, 'role_id' => $this->admin->company->roles()->where('key', 'loan_officer')->value('id')]);
        $this->actingAs($officer)->postJson('/api/v1/hrm/commission/calculate', ['period' => '2026-06'])->assertForbidden();
        $this->actingAs($officer)->postJson("/api/v1/penalties/{$penalty->id}/waive")->assertForbidden();
        $this->actingAs($officer)->postJson('/api/v1/capital/dividends', ['period' => '2026-06'])->assertForbidden();

        $this->signInAdmin();
        $this->postJson("/api/v1/penalties/{$penalty->id}/waive")->assertNotFound();
        $this->postJson("/api/v1/penalties/{$penalty->id}/pay", ['penart_paid' => 100])->assertNotFound();
        $this->assertFalse($penalty->fresh()->is_waived);
    }

    /**
     * A closed June 2026 (on 2026-07-01) with branch A distributable profit, its commission marked calculated (no staff, C1), its
     * profit in the Profit Account and the money in branch A's income pools; one shareholder holds all shares.
     *
     * @return array{0: AccountingPeriod}
     */
    private function closedMonthWithProfit(float $profit, ?float $interestCash = null, float $feeCash = 0, float $penaltyCash = 0): array
    {
        $a = $this->branchA->id;
        $interestCash ??= $profit;
        $lines = [
            ['account' => Account::Interest, 'branch' => $a, 'debit' => $interestCash],
            ['account' => Account::LoanFee, 'branch' => $a, 'debit' => $feeCash],
            ['account' => Account::Penalty, 'branch' => $a, 'debit' => $penaltyCash],
            ['account' => Account::RetainedProfit, 'branch' => $a, 'credit' => $profit],
        ];
        $difference = round($interestCash + $feeCash + $penaltyCash - $profit, 2);
        if (abs($difference) > 0.001) {
            $lines[] = ['account' => Account::Capital, 'credit' => max(0, $difference), 'debit' => max(0, -$difference)];
        }
        app(Ledger::class)->journal($this->admin->company_id, 'PROFIT', $lines);

        $period = AccountingPeriod::create(['company_id' => $this->admin->company_id, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'status' => AccountingPeriod::STATUS_CLOSED, 'closed_at' => now(), 'commission_calculated_at' => now()]);
        $period->results()->create(['branch_id' => $a, 'gross_profit' => $profit, 'net_profit' => $profit, 'distributable_profit' => $profit, 'commission_eligible' => true]);
        $this->establish([[$this->holder('OMEGA'), 100]]);

        return [$period];
    }

    /**
     * A penalty accrued under stream P's short-lived D9 rule (such rows exist only on the verification clone): the penalty row
     * linked to its Dr PENALTY RECEIVABLE / Cr PENALTY INCOME journal. New penalties are never accrued (rule 14).
     */
    private function legacyAccruedPenalty(Loan $loan, float $amount, string $date): Penalty
    {
        $penalty = Penalty::create(['company_id' => $loan->company_id, 'branch_id' => $loan->branch_id, 'customer_id' => $loan->customer_id, 'loan_id' => $loan->id, 'amount' => $amount, 'penalty_date' => $date]);
        $entry = app(Ledger::class)->journal($loan->company_id, 'PENALTY ACCRUAL '.$loan->loan_number, [
            ['account' => Account::PenaltyReceivable, 'branch' => $loan->branch_id, 'debit' => $amount],
            ['account' => Account::PenaltyIncome, 'branch' => $loan->branch_id, 'credit' => $amount],
        ], $penalty, CarbonImmutable::today(), $loan->branch_id, null, TransactionType::PenaltyAccrual);
        $penalty->forceFill(['accrual_journal_entry_id' => $entry->id])->save();

        return $penalty;
    }

    private function loan(Branch $branch, float $amount): Loan
    {
        $companyId = $this->admin->company_id;
        $customer = Customer::factory()->create(['company_id' => $companyId, 'branch_id' => $branch->id]);
        $category = LoanCategory::factory()->create(['company_id' => $companyId, 'insurance' => 1000, 'fee_value' => 5000]);
        $loans = app(LoanService::class);
        // The lending cash is HQ's (company level, no branch): the customer applies at $branch, but HQ pays.
        if (app(Ledger::class)->balance($companyId, Account::Principal) < $amount) {
            app(Ledger::class)->openingBalance($companyId, Account::Principal, $amount, 'FLOAT');
        }
        $loan = $loans->apply($customer, ['loan_category_id' => $category->id, 'amount_applied' => $amount, 'sessions' => 1, 'formula' => 'SIMPLE', 'fee_deduct' => true, 'reason' => 'BIASHARA']);
        $loans->approve($loan, $amount);
        $loans->withdraw($loan->fresh(), CarbonImmutable::today(), $this->admin);

        // New loans carry no insurance (specification §47); this chain covers a loan issued before that rule, which keeps its
        // 1,000 insurance and is collected exactly as issued.
        $loan->refresh()->forceFill(['insurance' => 1000, 'restoration' => round((float) $loan->restoration + 1000, 2)])->save();
        $loan->schedules()->increment('amount', 1000);

        return $loan->fresh();
    }

    private function staff(Branch $branch, int $salary, string $type = 'branch', array $attributes = []): Employee
    {
        $employee = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $branch->id] + $attributes);
        $employee->salaryInfo()->create(['salary' => $salary, 'salary_type' => $type, 'commission_eligible' => true, 'payment_method' => 'bank', 'account_name' => 'NMB', 'account_number' => '1', 'fee' => 0]);

        return $employee;
    }

    private function holder(string $firstName): ShareHolder
    {
        return ShareHolder::create(['company_id' => $this->admin->company_id, 'first_name' => $firstName, 'last_name' => 'HOLDER', 'mobile' => '0777', 'email' => strtolower($firstName).'@example.com', 'date_of_birth' => '1990-01-01']);
    }

    /**
     * @param  list<array{0: ShareHolder, 1: int}>  $allocations
     */
    private function establish(array $allocations): void
    {
        $total = array_sum(array_column($allocations, 1));
        $this->postJson('/api/v1/shares/structure', [
            'capital_basis' => $total * 1000,
            'total_shares' => $total,
            'established_on' => now()->toDateString(),
            'allocations' => array_map(fn (array $line): array => ['share_holder_id' => $line[0]->id, 'shares' => $line[1], 'treatment' => 'no_cash'], $allocations),
        ])->assertCreated();
    }

    /**
     * No check fails; warnings are only allowed for capital credited by test opening balances (no stakeholder).
     */
    private function assertIntegrityPasses(): void
    {
        $checks = collect(app(LedgerIntegrity::class)->run($this->admin->company_id)['checks']);
        $problems = $checks->whereIn('status', ['fail', 'warn'])->reject(fn (array $check): bool => $check['key'] === 'capital' && $check['status'] === 'warn');

        $this->assertSame([], $problems->values()->all());
    }

    private function assertBalance(float $expected, Account $account, ?int $branch = null, string $message = '', bool $allBranches = false): void
    {
        $this->assertEqualsWithDelta($expected, app(Ledger::class)->balance($this->admin->company_id, $account, $branch, allBranches: $allBranches), 0.001, $message ?: $account->value);
    }
}

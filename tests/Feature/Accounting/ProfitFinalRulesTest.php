<?php

namespace Tests\Feature\Accounting;

use App\Enums\Account;
use App\Enums\TransactionType;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\Capital;
use App\Models\CommissionAllocation;
use App\Models\DividendAllocation;
use App\Models\DividendDeclaration;
use App\Models\DividendDeclarationRequest;
use App\Models\Employee;
use App\Models\HrmSetting;
use App\Models\JournalEntry;
use App\Models\ShareHolder;
use App\Models\Zone;
use App\Services\Accounting\LedgerIntegrity;
use App\Services\DividendService;
use App\Services\Hrm\CommissionEngine;
use App\Services\Hrm\PayrollEngine;
use App\Services\Ledger;
use App\Services\PeriodClose;
use App\Services\Reports\Financial\FinancialScope;
use App\Services\Reports\Financial\PeriodResultsReport;
use App\Services\Reports\Financial\ProfitLossReport;
use App\Services\ShareholderOwnership;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

/**
 * Confirmed final profit rules (RULES_FINAL 1, 2, 3, 4, 5, 10, 15, 16; C1 and C4) through the real month-end close, commission
 * engine, dividend service (request → approval by a second user), reports and integrity checks.
 */
class ProfitFinalRulesTest extends TestCase
{
    use RefreshDatabase, UsesSecondApprover;

    private const JULY = '2026-07-01';

    private Employee $admin;

    private Branch $branchA;

    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-08-02 09:00:00'));
        $this->admin = $this->signInAdmin();
        HrmSetting::forCompany($this->admin->company_id)->update(['commission_pool_percent' => 10, 'zone_override_percent' => 5, 'staff_fund_percent' => 10]);

        $zone = Zone::create(['company_id' => $this->admin->company_id, 'name' => 'LAKE']);
        $this->branchA = $this->admin->branch;
        $this->branchA->update(['zone_id' => $zone->id, 'name' => 'BRANCH A']);
        $this->branchB = Branch::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'BRANCH B']);
    }

    public function test_rule_1_dividends_need_the_authoritative_closed_status_not_just_calculated_results(): void
    {
        $this->establish([[$this->holder('ALPHA'), 100]]);
        $this->income($this->branchA, 102040.82);
        $close = app(PeriodClose::class);
        $period = $close->calculate($this->admin->company_id, $this->july());
        $this->assertSame(AccountingPeriod::STATUS_OPEN, $period->status);
        $this->assertEquals(100000, $period->results->firstWhere('branch_id', $this->branchA->id)->distributable_profit);

        $message = 'July 2026 is not closed. Dividends can only be declared for a closed accounting period.';
        $this->getJson('/api/v1/capital/dividends/preview?period=2026-07')->assertOk()
            ->assertJsonPath('data.period_closed', false)
            ->assertJsonPath('data.can_declare', false)
            ->assertJsonPath('data.blocking_reason', $message);
        $this->postJson('/api/v1/capital/dividends', ['period' => '2026-07'])->assertUnprocessable()
            ->assertJsonPath('errors.period.0', $message);
        $this->assertSame(0, DividendDeclaration::count(), 'rule 1: an open period is never declared');

        $close->close($period, $this->admin);
        $this->getJson('/api/v1/capital/dividends/preview?period=2026-07')->assertOk()
            ->assertJsonPath('data.period_closed', true)
            ->assertJsonPath('data.can_declare', false)
            ->assertJsonPath('data.blocking_reason', 'Commission for July 2026 must be calculated before a dividend can be declared.');

        app(CommissionEngine::class)->calculate($this->admin->company_id, $this->july(), $this->admin);
        $this->getJson('/api/v1/capital/dividends/preview?period=2026-07')->assertOk()
            ->assertJsonPath('data.can_declare', true);
    }

    public function test_rules_2_3_10_16_distributable_profit_splits_10_63_27_with_the_zone_manager_inside_the_ten_percent(): void
    {
        $companyId = $this->admin->company_id;
        $a = $this->branchA->id;
        $staff1 = $this->staff($this->branchA, 300000);
        $staff2 = $this->staff($this->branchA, 100000);
        $manager = $this->staff($this->branchA, 500000, 'zone_manager', ['zone_id' => $this->branchA->zone_id]);
        $alpha = $this->holder('ALPHA');
        $beta = $this->holder('BETA');
        $this->establish([[$alpha, 600], [$beta, 400]]);

        // Eligible cash income: interest (new entry already net of reserve + legacy entry with reserve inside income), fee, penalty.
        $ledger = app(Ledger::class);
        $july = CarbonImmutable::parse('2026-07-15');
        $ledger->journal($companyId, 'LOAN RETURN NEW', [
            ['account' => Account::Interest, 'branch' => $a, 'debit' => 10154081.63],
            ['account' => Account::Reserve, 'branch' => $a, 'debit' => 500000],
            ['account' => Account::InterestIncome, 'branch' => $a, 'credit' => 10154081.63],
            ['account' => Account::InterestReserve, 'branch' => $a, 'credit' => 500000],
        ], null, $july, $a, type: TransactionType::LoanRepayment);
        $ledger->journal($companyId, 'LOAN RETURN LEGACY', [
            ['account' => Account::Interest, 'branch' => $a, 'debit' => 80000],
            ['account' => Account::Reserve, 'branch' => $a, 'debit' => 20000],
            ['account' => Account::InterestIncome, 'branch' => $a, 'credit' => 100000],
        ], null, $july, $a, type: TransactionType::LoanRepayment);
        $ledger->journal($companyId, 'FEE PENALTY INSURANCE', [
            ['account' => Account::LoanFee, 'branch' => $a, 'debit' => 50000],
            ['account' => Account::FeeIncome, 'branch' => $a, 'credit' => 50000],
            ['account' => Account::Penalty, 'branch' => $a, 'debit' => 20000],
            ['account' => Account::PenaltyIncome, 'branch' => $a, 'credit' => 20000],
            ['account' => Account::Insurance, 'branch' => $a, 'debit' => 3000],
            ['account' => Account::InsuranceReserve, 'branch' => $a, 'credit' => 3000],
        ], null, $july, $a, type: TransactionType::LoanRepayment);
        $ledger->journal($companyId, 'RENT', [
            ['account' => Account::OperatingExpense, 'branch' => $a, 'debit' => 100000],
            ['account' => Account::Interest, 'branch' => $a, 'credit' => 100000],
        ], null, $july, $a, type: TransactionType::Expense);

        $close = app(PeriodClose::class);
        $reserveFundBefore = $this->balance(Account::Reserve, $a);
        $period = $close->close($close->calculate($companyId, $this->july()), $this->admin);
        $result = $period->results->firstWhere('branch_id', $a);

        // Rule 2: eligible income − expenses − loss b/f − HQ 2 % hold = distributable (insurance and reserve excluded).
        $this->assertEquals(
            [10234081.63, 520000, 50000, 20000, 10304081.63, 100000, 10204081.63, 0, 10204081.63, 204081.63, 10000000],
            [(float) $result->interest_income, (float) $result->reserve_amount, (float) $result->fee_income, (float) $result->penalty_income, (float) $result->total_income, (float) $result->expenses, (float) $result->gross_profit, (float) $result->loss_brought_forward, (float) $result->net_profit, (float) $result->hq_hold_amount, (float) $result->distributable_profit],
        );
        // Rule 3: the close keeps the reserve out of profit — legacy reserve is reclassified to INTEREST RESERVE (equity side only),
        // the RESERVE A/C money is untouched.
        $this->assertBalance($reserveFundBefore, Account::Reserve, $a, 'rule 3: close does not move the RESERVE A/C');
        $this->assertBalance(520000, Account::InterestReserve, $a, 'rule 3: legacy reserve reclassified out of income, never to profit');
        // Rule 16: the HQ 2 % hold is applied once.
        $this->assertBalance(10000000, Account::RetainedProfit, $a, 'rule 16: branch profit account holds exactly the distributable profit');
        $this->assertBalance(204081.63, Account::RetainedProfit, null, 'rule 16: HQ profit account holds the 2 % hold once');

        $scope = new FinancialScope($companyId, null, true, $this->july(), CarbonImmutable::parse('2026-07-31'));
        $pnl = collect(app(ProfitLossReport::class)->branchPnl($scope, [$a => 'BRANCH A'])['rows'])->firstWhere('branch_id', (string) $a);
        $this->assertEquals([10204081.63, 204081.63, 10000000], [$pnl['net_profit'], $pnl['hq_hold_amount'], $pnl['distributable_profit']], 'rule 16: branch P&L applies the hold once');
        $consolidated = app(ProfitLossReport::class)->consolidated($scope);
        $this->assertEquals(10204081.63, $consolidated['net_profit'], 'consolidated net profit is before the hold (the hold is not an expense)');
        $this->assertEquals(3000, $consolidated['insurance_income']['amount'], 'rule 15: insurance collections shown apart');
        $this->assertEquals(204081.63, app(PeriodResultsReport::class)->hqHold($scope)['total_held'], 'rule 16: HQ hold report');

        // Commission = 10 % of distributable, zone manager 5 % carved inside it.
        $interestReserveBefore = $this->balance(Account::InterestReserve, $a);
        $reserveFundBefore = $this->balance(Account::Reserve, $a);
        $engine = app(CommissionEngine::class);
        $engine->calculate($companyId, $this->july(), $this->admin);
        $this->assertEquals(1000000, round((float) CommissionAllocation::sum('amount'), 2), 'rule 2: commission = 10 % of distributable (exact cents)');
        $this->assertEquals(50000, CommissionAllocation::where('employee_id', $manager->id)->value('amount'), 'rule 2: zone manager override inside the 10 %');
        $this->assertEquals([712500, 237500], [(float) CommissionAllocation::where('employee_id', $staff1->id)->value('amount'), (float) CommissionAllocation::where('employee_id', $staff2->id)->value('amount')]);
        $this->assertBalance(9000000, Account::RetainedProfit, $a);
        $this->assertBalance(204081.63, Account::RetainedProfit, null, 'rule 16: commission does not touch the HQ hold');

        // Dividend base = distributable − calculated commission; 70/30 of the remaining 90 %.
        $capitalBefore = $this->balance(Account::Capital, null, true);
        $capitalRows = Capital::count();
        $ownershipBefore = $this->ownership();
        $preview = app(DividendService::class)->preview($companyId, $this->july());
        $this->assertTrue($preview['can_declare'], (string) $preview['blocking_reason']);
        $this->assertEquals([10000000, 1000000, 9000000, 2700000, 6300000], [$preview['distributable_profit'], $preview['commission_amount'], $preview['base_amount'], $preview['dividend_pool'], $preview['reinvestment_amount']], 'rule 2: 10 / 63 / 27');

        $requestId = $this->postJson('/api/v1/capital/dividends', ['period' => '2026-07'])->assertCreated()->json('data.id');
        $this->approveAsSecondUser($this->admin, "/api/v1/capital/dividends/requests/{$requestId}/approve");
        $declaration = DividendDeclaration::sole();
        $this->assertEquals([9000000, 1000000, 2700000, 6300000], [(float) $declaration->base_amount, (float) $declaration->commission_amount, (float) $declaration->dividend_amount, (float) $declaration->reinvest_amount]);
        $this->assertBalance(0, Account::RetainedProfit, $a);
        $this->assertBalance(204081.63, Account::RetainedProfit, null, 'rule 16: dividends do not re-apply or take the HQ hold');

        // Rule 10: reinvestment is REINVESTED PROFIT and principal, never capital or contributions; dividends by share register.
        $this->assertBalance(6300000, Account::ReinvestedProfit, null, 'rule 10: reinvestment is reinvested profit');
        $this->assertBalance($capitalBefore, Account::Capital, null, 'rule 10: capital unchanged', true);
        $this->assertSame($capitalRows, Capital::count(), 'rule 10: no capital contribution created');
        $this->assertEquals($ownershipBefore, $this->ownership(), 'rule 10: shareholder contributions and shares unchanged');
        $this->assertSame(['1620000.00', '1080000.00'], DividendAllocation::orderBy('share_holder_id')->pluck('amount')->all(), 'rule 10: 60 % / 40 % of the pool by share register');

        // Rule 3: commission and declaration never touch the reserve; the reinvestment is funded from INTEREST, not RESERVE.
        $this->assertBalance($reserveFundBefore, Account::Reserve, $a, 'rule 3: RESERVE A/C unchanged');
        $this->assertBalance($interestReserveBefore, Account::InterestReserve, $a, 'rule 3: INTEREST RESERVE unchanged');
        $sources = JournalEntry::with('lines.account')->findOrFail($declaration->reinvestment_journal_entry_id)->lines->filter(fn ($line): bool => (float) $line->credit > 0)->map(fn ($line): string => $line->account->key->value)->unique()->values()->all();
        $this->assertSame(['interest'], $sources);

        $this->postJson('/api/v1/hrm/commission/calculate', ['period' => '2026-07'])->assertUnprocessable()
            ->assertJsonPath('errors.period.0', CommissionEngine::LOCKED_BY_DIVIDEND_MESSAGE);

        $checks = $this->integrity();
        $this->assertSame('pass', $checks['profit_distribution']['status'], json_encode($checks['profit_distribution']));
        $this->assertSame('pass', $checks['reserve_untouched_by_profit_chain']['status'], json_encode($checks['reserve_untouched_by_profit_chain']));
    }

    /**
     * C4: branch A (zone LAKE without a zone manager) pays its staff 95 % and returns the 5 % to profit; branch B (no staff)
     * returns its whole pool.
     */
    public function test_rule_4_a_branch_pool_without_eligible_staff_returns_to_profit_and_enters_the_dividend_base(): void
    {
        $companyId = $this->admin->company_id;
        $a = $this->branchA->id;
        $b = $this->branchB->id;
        $this->staff($this->branchA, 100000);
        $this->establish([[$this->holder('ALPHA'), 100]]);
        $this->income($this->branchA, 102040.82);
        $this->income($this->branchB, 51020.41);
        $close = app(PeriodClose::class);
        $close->close($close->calculate($companyId, $this->july()), $this->admin);

        $engine = app(CommissionEngine::class);
        $engine->calculate($companyId, $this->july(), $this->admin);

        // DB: no allocation for branch B; ledger: no journal for B, B's profit account keeps its full distributable profit.
        $this->assertSame(0, CommissionAllocation::where('branch_id', $b)->count(), 'rule 4: no allocation');
        $this->assertEquals(9500, round((float) CommissionAllocation::where('branch_id', $a)->sum('amount'), 2), 'C4: staff get 95 % without a zone manager');
        $journals = JournalEntry::where('transaction_type', TransactionType::CommissionAllocation->value)->get();
        $this->assertSame([$a], $journals->pluck('branch_id')->all(), 'rule 4: no journal for the pool without staff');
        $this->assertBalance(50000, Account::RetainedProfit, $b, 'rule 4: the pool stays in profit');
        $this->assertBalance(90500, Account::RetainedProfit, $a, 'C4: the 5 % without a zone manager stays in profit');
        $this->assertBalance(9500, Account::CommissionPayable, null, 'rule 4: returned amounts are not a liability', true);
        $this->assertBalance(0, Account::CommissionExpense, null, 'rule 4: not an expense', true);
        $this->assertBalance(0, Account::Suspense, null, 'rule 4: not suspense', true);

        // Report.
        $report = $engine->report($companyId, $this->july());
        $this->assertTrue($report['calculated']);
        $this->assertEquals(9500, $report['total_commission']);
        $this->assertEquals(5500, $report['total_returned_to_profit']);
        $this->assertEquals([500, 5000], [$report['total_returned_no_zone_manager'], $report['total_returned_no_staff']]);
        $branchB = collect($report['branches'])->firstWhere('branch_id', $b);
        $this->assertEquals([5000, 5000, 5000, 0, []], [$branchB['pool_amount'], $branchB['returned_to_profit_amount'], $branchB['returned_no_staff_amount'], $branchB['returned_no_zone_manager_amount'], $branchB['staff']]);
        $branchA = collect($report['branches'])->firstWhere('branch_id', $a);
        $this->assertEquals([500, 500, 0, 9500, 0], [$branchA['returned_to_profit_amount'], $branchA['returned_no_zone_manager_amount'], $branchA['returned_no_staff_amount'], $branchA['staff_pool_amount'], $branchA['zone_manager_amount']]);

        // Dividend base subtracts only the calculated (stored) commission.
        $preview = app(DividendService::class)->preview($companyId, $this->july());
        $this->assertTrue($preview['commission_calculated']);
        $this->assertEquals([150000, 9500, 140500, 42150, 98350], [$preview['distributable_profit'], $preview['commission_amount'], $preview['base_amount'], $preview['dividend_pool'], $preview['reinvestment_amount']], 'rule 4: returned pool enters the base');
        $split = collect($preview['branches'])->keyBy('branch_id');
        $this->assertEquals([90500, 50000], [$split[$a]['base_amount'], $split[$b]['base_amount']]);

        $declaration = $this->declareAndApprove();
        $this->assertEquals([140500, 9500], [(float) $declaration->base_amount, (float) $declaration->commission_amount]);
        $this->assertBalance(0, Account::RetainedProfit, $a);
        $this->assertBalance(0, Account::RetainedProfit, $b);
        $this->assertSame('pass', $this->integrity()['profit_distribution']['status']);
    }

    public function test_rule_4_when_no_branch_has_eligible_staff_the_calculation_is_still_recorded(): void
    {
        $this->establish([[$this->holder('ALPHA'), 100]]);
        $this->income($this->branchB, 51020.41);
        $close = app(PeriodClose::class);
        $close->close($close->calculate($this->admin->company_id, $this->july()), $this->admin);

        $this->postJson('/api/v1/hrm/commission/calculate', ['period' => '2026-07'])->assertSuccessful();

        $this->assertSame(0, CommissionAllocation::count());
        $this->assertNotNull(AccountingPeriod::sole()->commission_calculated_at);
        $report = app(CommissionEngine::class)->report($this->admin->company_id, $this->july());
        $this->assertTrue($report['calculated']);
        $this->assertSame(CommissionEngine::STATUS_ALLOCATED, $report['allocation_status']);
        $preview = app(DividendService::class)->preview($this->admin->company_id, $this->july());
        $this->assertTrue($preview['commission_calculated'], 'a calculation that returned every pool to profit is not "not calculated"');
        $this->assertTrue($preview['can_declare'], (string) $preview['blocking_reason']);
        $this->assertEquals([0, 50000], [$preview['commission_amount'], $preview['base_amount']]);
    }

    public function test_rule_5_the_dividend_base_subtracts_only_commission_already_calculated(): void
    {
        $companyId = $this->admin->company_id;
        $this->staff($this->branchA, 100000);
        $this->establish([[$this->holder('ALPHA'), 100]]);
        $this->income($this->branchA, 10204081.63);
        $close = app(PeriodClose::class);
        $close->close($close->calculate($companyId, $this->july()), $this->admin);

        app(CommissionEngine::class)->calculate($companyId, $this->july(), $this->admin);
        // A later setting change makes more commission "expected" than calculated — it must not reduce the base.
        HrmSetting::forCompany($companyId)->update(['commission_pool_percent' => 15]);

        // Pool 1,000,000: staff 950,000, the 5 % without a zone manager (50,000) stays in profit (C4).
        $profit = app(DividendService::class)->availableProfit($companyId, $this->july());
        $this->assertEquals([10000000, 950000, 9050000, 9050000], [$profit['distributable_profit'], $profit['commission_amount'], $profit['base_amount'], $profit['profit_available']], 'rule 5: 10M − calculated 950K (expected commission ignored)');
        $this->assertTrue($profit['commission_calculated']);

        $declaration = $this->declareAndApprove();
        $this->assertEquals([9050000, 950000], [(float) $declaration->base_amount, (float) $declaration->commission_amount]);

        $this->postJson('/api/v1/hrm/commission/calculate', ['period' => '2026-07'])->assertUnprocessable()
            ->assertJsonPath('errors.period.0', CommissionEngine::LOCKED_BY_DIVIDEND_MESSAGE);
        $this->assertEquals(950000, round((float) CommissionAllocation::sum('amount'), 2), 'rule 5: recalculation after declaration posts nothing');
    }

    public function test_c1_a_dividend_cannot_be_requested_or_approved_before_commission_is_calculated(): void
    {
        $companyId = $this->admin->company_id;
        $this->staff($this->branchA, 100000);
        $this->establish([[$this->holder('ALPHA'), 100]]);
        $this->income($this->branchA, 10204081.63);
        $close = app(PeriodClose::class);
        $close->close($close->calculate($companyId, $this->july()), $this->admin);

        $blocked = 'Commission for July 2026 must be calculated before a dividend can be declared.';
        $this->getJson('/api/v1/capital/dividends/preview?period=2026-07')->assertOk()
            ->assertJsonPath('data.can_declare', false)
            ->assertJsonPath('data.blocking_reason', $blocked)
            ->assertJsonPath('data.commission_calculated', false)
            ->assertJsonMissingPath('data.commission_warning');
        $this->getJson('/api/v1/capital/dividends/summary?period=2026-07')->assertOk()
            ->assertJsonPath('data.can_declare', false)
            ->assertJsonPath('data.blocking_reason', $blocked);

        $this->postJson('/api/v1/capital/dividends', ['period' => '2026-07'])->assertUnprocessable()->assertJsonPath('errors.period.0', $blocked);
        $this->assertSame(0, DividendDeclarationRequest::count());
        $this->assertSame(0, DividendDeclaration::count());
        $this->assertSame(0, JournalEntry::whereIn('transaction_type', [TransactionType::DividendDeclaration->value, TransactionType::ProfitReinvestment->value])->count());

        // Commission calculated → the request is accepted; while it is pending commission cannot be recalculated.
        app(CommissionEngine::class)->calculate($companyId, $this->july(), $this->admin);
        $requestId = $this->postJson('/api/v1/capital/dividends', ['period' => '2026-07'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/hrm/commission/calculate', ['period' => '2026-07'])->assertUnprocessable()
            ->assertJsonPath('errors.period.0', CommissionEngine::LOCKED_BY_DIVIDEND_REQUEST_MESSAGE);

        // A second authorised user approves: the declaration subtracts the calculated commission.
        $period = AccountingPeriod::sole();
        $allocations = CommissionAllocation::count();
        $this->assertGreaterThan(0, $allocations);
        $this->approveAsSecondUser($this->admin, "/api/v1/capital/dividends/requests/{$requestId}/approve");
        $declaration = DividendDeclaration::sole();
        $this->assertEquals([9050000, 950000], [(float) $declaration->base_amount, (float) $declaration->commission_amount]);
        $this->assertNotNull($period->fresh()->commission_calculated_at);

        // Payroll generation does not recalculate the locked month, and no longer carries commission (spec §21 / §22).
        $run = app(PayrollEngine::class)->generate($companyId, $this->july(), $this->admin);
        $this->assertEquals(0, $run->items()->sum('commission'));
        $this->assertSame($allocations, CommissionAllocation::count());
        $this->assertSame(1, JournalEntry::where('transaction_type', TransactionType::CommissionAllocation->value)->count());
        $this->assertSame('pass', $this->integrity()['profit_distribution']['status']);
    }

    public function test_c1_approval_re_validates_that_commission_is_still_calculated(): void
    {
        $companyId = $this->admin->company_id;
        $this->establish([[$this->holder('ALPHA'), 100]]);
        $this->income($this->branchB, 51020.41);
        $close = app(PeriodClose::class);
        $close->close($close->calculate($companyId, $this->july()), $this->admin);
        app(CommissionEngine::class)->calculate($companyId, $this->july(), $this->admin);

        $requestId = $this->postJson('/api/v1/capital/dividends', ['period' => '2026-07'])->assertCreated()->json('data.id');
        AccountingPeriod::sole()->update(['commission_calculated_at' => null]);

        $this->asApprover($this->admin, fn () => $this->postJson("/api/v1/capital/dividends/requests/{$requestId}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('errors.period.0', 'Commission for July 2026 must be calculated before a dividend can be declared.'));
        $this->assertSame(0, DividendDeclaration::count());
        $this->assertSame(DividendDeclarationRequest::STATUS_PENDING, DividendDeclarationRequest::sole()->status);
    }

    public function test_rule_15_insurance_collections_of_both_kinds_are_reserved_and_never_profit(): void
    {
        $companyId = $this->admin->company_id;
        $a = $this->branchA->id;
        $ledger = app(Ledger::class);
        $july = CarbonImmutable::parse('2026-07-10');
        $ledger->journal($companyId, 'LOAN RETURN LEGACY INSURANCE', [
            ['account' => Account::Interest, 'branch' => $a, 'debit' => 10000],
            ['account' => Account::InterestIncome, 'branch' => $a, 'credit' => 10000],
            ['account' => Account::Insurance, 'branch' => $a, 'debit' => 3000],
            ['account' => Account::InsuranceIncome, 'branch' => $a, 'credit' => 3000],
        ], null, $july, $a, type: TransactionType::LoanRepayment);
        $ledger->journal($companyId, 'LOAN RETURN NEW INSURANCE', [
            ['account' => Account::Insurance, 'branch' => $a, 'debit' => 2000],
            ['account' => Account::InsuranceReserve, 'branch' => $a, 'credit' => 2000],
        ], null, $july, $a, type: TransactionType::LoanRepayment);

        $scope = new FinancialScope($companyId, null, true, $this->july(), CarbonImmutable::parse('2026-07-31'));
        $before = app(ProfitLossReport::class)->consolidated($scope);

        $close = app(PeriodClose::class);
        $period = $close->close($close->calculate($companyId, $this->july()), $this->admin);
        $result = $period->results->firstWhere('branch_id', $a);
        $this->assertEquals([10000, 10000, 9800], [(float) $result->total_income, (float) $result->gross_profit, (float) $result->distributable_profit], 'rule 15: insurance is not eligible income');
        $this->assertBalance(5000, Account::InsuranceReserve, $a, 'rule 15: legacy income closed to the reserve + new collections');
        $this->assertBalance(0, Account::InsuranceIncome, $a);
        $this->assertBalance(9800, Account::RetainedProfit, $a);

        $after = app(ProfitLossReport::class)->consolidated($scope);
        foreach ([$before, $after] as $report) {
            $this->assertEquals(10000, $report['net_profit']);
            $this->assertEquals([5000, 3000, 2000], [$report['insurance_income']['amount'], $report['insurance_income']['legacy_income_amount'], $report['insurance_income']['reserve_amount']], 'rule 15: both kinds shown below net profit');
        }

        $check = $this->integrity()['insurance_reserve'];
        $this->assertSame('pass', $check['status'], json_encode($check));
        $this->assertEquals([5000, 2000, 3000], [$check['details']['ledger'], $check['details']['collected_to_reserve'], $check['details']['legacy_income_reclassified']]);

        $ledger->journal($companyId, 'WRONG', [
            ['account' => Account::Interest, 'branch' => $a, 'debit' => 100],
            ['account' => Account::InsuranceReserve, 'branch' => $a, 'credit' => 100],
        ]);
        $this->assertSame('fail', $this->integrity()['insurance_reserve']['status'], 'insurance reserve credited without insurance collected');
    }

    public function test_rule_3_integrity_flags_a_profit_chain_entry_that_moves_the_reserve(): void
    {
        $a = $this->branchA->id;
        $ledger = app(Ledger::class);
        $ledger->openingBalance($this->admin->company_id, Account::Reserve, 1000, 'RESERVE', $a);
        $this->assertSame('pass', $this->integrity()['reserve_untouched_by_profit_chain']['status']);

        $ledger->journal($this->admin->company_id, 'BAD REINVESTMENT', [
            ['account' => Account::Principal, 'branch' => $a, 'debit' => 1000],
            ['account' => Account::Reserve, 'branch' => $a, 'credit' => 1000],
        ], type: TransactionType::ProfitReinvestment);

        $check = $this->integrity()['reserve_untouched_by_profit_chain'];
        $this->assertSame('fail', $check['status']);
        $this->assertSame('reserve', $check['details']['lines'][0]['account']);
    }

    /**
     * Request July's declaration as the admin and approve it as a second authorised user (rule 6).
     */
    private function declareAndApprove(): DividendDeclaration
    {
        $request = app(DividendService::class)->declare($this->admin->company_id, $this->july(), $this->admin);

        return app(DividendService::class)->approveDeclaration($request, $this->secondApprover($this->admin));
    }

    private function july(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::JULY);
    }

    /**
     * Interest income collected in July for a branch (new-style entry, no reserve).
     */
    private function income(Branch $branch, float $amount): void
    {
        app(Ledger::class)->journal($this->admin->company_id, 'INTEREST '.$branch->name, [
            ['account' => Account::Interest, 'branch' => $branch->id, 'debit' => $amount],
            ['account' => Account::InterestIncome, 'branch' => $branch->id, 'credit' => $amount],
        ], null, CarbonImmutable::parse('2026-07-20'), $branch->id, type: TransactionType::LoanRepayment);
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
            'established_on' => '2026-06-01',
            'allocations' => array_map(fn (array $line): array => ['share_holder_id' => $line[0]->id, 'shares' => $line[1], 'treatment' => 'no_cash'], $allocations),
        ])->assertCreated();
    }

    /**
     * @return array<int, array{total_contributed: float, shares: int}>
     */
    private function ownership(): array
    {
        return app(ShareholderOwnership::class)->summary($this->admin->company_id)
            ->mapWithKeys(fn (array $row): array => [$row['share_holder']->id => ['total_contributed' => $row['total_contributed'], 'shares' => $row['shares']]])
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function integrity(): array
    {
        return collect(app(LedgerIntegrity::class)->run($this->admin->company_id)['checks'])->keyBy('key')->all();
    }

    private function balance(Account $account, ?int $branch = null, bool $allBranches = false): float
    {
        return app(Ledger::class)->balance($this->admin->company_id, $account, $branch, allBranches: $allBranches);
    }

    private function assertBalance(float $expected, Account $account, ?int $branch = null, string $message = '', bool $allBranches = false): void
    {
        $this->assertEqualsWithDelta($expected, $this->balance($account, $branch, $allBranches), 0.001, $message ?: $account->value);
    }
}

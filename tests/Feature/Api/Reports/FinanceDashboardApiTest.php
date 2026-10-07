<?php

namespace Tests\Feature\Api\Reports;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\LoanService;
use App\Services\Reports\Financial\CashFlowReport;
use App\Services\Reports\Financial\FinancialScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\Accounting\AccountingTestHelpers;
use Tests\Feature\Api\Loans\BuildsServiceLoans;
use Tests\TestCase;

/**
 * Finance Dashboard (GET /dashboard/finance): every figure comes from the records — loans disbursed and repaid through the
 * loan service, payments with their bank, the ledger — and agrees with the existing reports.
 */
class FinanceDashboardApiTest extends TestCase
{
    use AccountingTestHelpers, BuildsServiceLoans, RefreshDatabase;

    private Employee $admin;

    private Employee $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->finance = $this->employeeWithRole($this->admin, 'finance');
    }

    public function test_finance_sees_the_month_built_from_real_loans_repayments_and_payments(): void
    {
        $today = CarbonImmutable::today();
        $loan = $this->serviceLoan($this->admin, amount: 100000, legacyInsurance: 0);
        $loan->schedules()->update(['due_date' => $today->toDateString()]);
        $expected = round((float) $loan->schedules()->sum('amount'), 2);
        $disbursed = (float) LoanTransaction::where('loan_id', $loan->id)->where('type', 'withdrawal')->sum('amount');

        $loans = app(LoanService::class);
        $loans->deposit($loan->fresh(), 20000, $today, 'CASH', $this->admin);
        $bankRepayment = $loans->deposit($loan->fresh(), 15000, $today, 'BANK', $this->admin);
        $this->paidThroughBank($loan, $bankRepayment, 'CRDB');

        $data = $this->actingAs($this->finance)->getJson('/api/v1/dashboard/finance?month='.$today->format('Y-m'))->assertOk()->json('data');

        $this->assertSame($today->format('F Y'), $data['month_label']);
        $this->assertEquals($disbursed, $data['cards']['disbursed_today']);
        $this->assertEquals(35000, $data['cards']['collected_today']);
        $this->assertEquals($expected, $data['cards']['expected_month']);
        $this->assertEquals(35000, $data['cards']['collected_month']);
        $this->assertEquals(round(35000 / $expected * 100, 1), $data['cards']['collected_month_percent']);
        $this->assertEquals(['expected' => $expected, 'actual' => 35000, 'cash' => 35000, 'offset' => 0, 'outstanding' => round($expected - 35000, 2)], $data['expected_vs_actual']);

        $methods = collect($data['payment_methods']['rows'])->keyBy('key');
        $this->assertEquals(35000, $data['payment_methods']['total']);
        $this->assertEquals([20000, 15000, 0], [$methods['cash']['amount'], $methods['bank']['amount'], $methods['mobile']['amount']]);
        $this->assertEquals(round(15000 / 35000 * 100, 1), $methods['bank']['percent']);

        $channels = collect($data['channels'])->keyBy('label');
        $this->assertEquals(15000, $channels['CRDB']['collected'], 'Bank payments are shown under the bank that received them.');
        $this->assertSame(1, $channels['CRDB']['clients']);
        $this->assertEquals($expected, $channels['CRDB']['expected'], 'The customer last repaid through CRDB, so their instalment is expected there.');
        $this->assertEquals(20000, $channels['Cash Collection']['collected']);

        $this->assertGreaterThan(0, $data['cards']['loan_outstanding']);
        $cashFlow = app(CashFlowReport::class)->build(new FinancialScope($this->admin->company_id, null, true, $today->startOfMonth(), $today->endOfMonth()));
        $this->assertEquals(['opening' => $cashFlow['opening'], 'cash_in' => $cashFlow['total_inflow'], 'cash_out' => $cashFlow['total_outflow'], 'closing' => $cashFlow['closing']], $data['cash_flow']);
        $this->assertEquals($cashFlow['closing'], $data['cards']['cash_balance'], 'The Total Cash card is the cash flow closing balance.');
        $accounts = collect($data['cards']['cash_accounts']);
        $this->assertEquals($data['cards']['cash_balance'], round($accounts->where('in_total', true)->sum('amount'), 2), 'The accounts behind the Total Cash card add up to it.');
        $this->assertSame(
            ['OPERATION PRINCIPAL', 'OPERATION INCOME', 'DIVIDEND (inside OPERATION INCOME)', 'RESERVE', 'FUND', 'SAVINGS'],
            $accounts->pluck('label')->intersect(['OPERATION PRINCIPAL', 'OPERATION INCOME', 'DIVIDEND (inside OPERATION INCOME)', 'RESERVE', 'FUND', 'SAVINGS'])->values()->all(),
            'The HQ pools are always listed, in order, even when empty.',
        );
        $this->assertFalse($accounts->firstWhere('label', 'DIVIDEND (inside OPERATION INCOME)')['in_total'], 'Declared dividends are still held in OPERATION INCOME.');
        $this->assertContains('OPERATION PRINCIPAL', $accounts->pluck('label'), 'Loans are funded from, and repaid to, OPERATION PRINCIPAL.');

        $income = collect($data['income_expenses']['income'])->keyBy('key');
        $this->assertGreaterThan(0, $data['income_expenses']['total_income']);
        $this->assertEquals($data['income_expenses']['total_income'], round($income->sum('amount'), 2), 'The income lines add up to the total.');
        $this->assertEquals(round($data['income_expenses']['total_income'] - $data['income_expenses']['total_expenses'], 2), $data['income_expenses']['net']);
    }

    public function test_a_repayment_settled_by_a_top_up_counts_as_an_offset_not_as_cash(): void
    {
        $today = CarbonImmutable::today();
        $loan = $this->serviceLoan($this->admin, amount: 100000, legacyInsurance: 0);
        $repayment = app(LoanService::class)->deposit($loan->fresh(), 25000, $today, 'CASH', $this->admin);
        DB::table('loan_offsets')->insert([
            'company_id' => $this->admin->company_id, 'branch_id' => $loan->branch_id, 'customer_id' => $loan->customer_id,
            'old_loan_id' => $loan->id, 'new_loan_id' => $loan->id, 'loan_transaction_id' => $repayment->id, 'amount' => 25000,
            'principal_amount' => 25000, 'penalty_amount' => 0, 'interest_amount' => 0, 'salary_advance_amount' => 0, 'insurance_amount' => 0,
            'cash_disbursed' => 0, 'settled_on' => $today->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->actingAs($this->finance)->getJson('/api/v1/dashboard/finance')->assertOk()->json('data');

        $this->assertEquals([25000, 0], [$data['expected_vs_actual']['offset'], $data['expected_vs_actual']['cash']]);
        $this->assertEquals(25000, collect($data['payment_methods']['rows'])->firstWhere('key', 'offset')['amount']);
    }

    public function test_a_reversed_repayment_is_not_counted(): void
    {
        $today = CarbonImmutable::today();
        $loan = $this->serviceLoan($this->admin, amount: 100000, legacyInsurance: 0);
        $repayment = app(LoanService::class)->deposit($loan->fresh(), 10000, $today, 'CASH', $this->admin);
        $repayment->forceFill(['reversed_at' => now()])->save();

        $data = $this->actingAs($this->finance)->getJson('/api/v1/dashboard/finance')->assertOk()->json('data');

        $this->assertEquals(0, $data['cards']['collected_today']);
        $this->assertEquals(0, $data['payment_methods']['total']);
    }

    public function test_one_branch_shows_only_that_branchs_loans(): void
    {
        $other = Branch::factory()->create(['company_id' => $this->admin->company_id]);
        $this->serviceLoan($this->admin, amount: 100000, legacyInsurance: 0);
        $otherLoan = $this->serviceLoan($this->admin, amount: 50000, branchId: $other->id, legacyInsurance: 0);
        $otherDisbursed = (float) LoanTransaction::where('loan_id', $otherLoan->id)->where('type', 'withdrawal')->sum('amount');

        $data = $this->actingAs($this->finance)->getJson("/api/v1/dashboard/finance?branch_id={$other->id}")->assertOk()->json('data');

        $this->assertEquals($otherDisbursed, $data['cards']['disbursed_today']);
        // The Branch List popup always lists every branch, whatever branch the dashboard is filtered to.
        $this->assertContains($other->name, array_column($data['branch_accounts']['rows'], 'name'));
        $this->assertGreaterThan(1, count($data['branch_accounts']['rows']));
    }

    public function test_only_company_wide_staff_who_may_view_the_accounts_see_it(): void
    {
        $manager = $this->employeeWithRole($this->admin, 'branch_manager');
        $this->actingAs($manager)->getJson('/api/v1/dashboard/finance')->assertForbidden();

        $this->actingAs($this->finance)->getJson('/api/v1/dashboard/finance?month=2026-13')->assertUnprocessable()->assertJsonValidationErrors(['month']);
        $foreign = Branch::factory()->create();
        $this->actingAs($this->finance)->getJson("/api/v1/dashboard/finance?branch_id={$foreign->id}")->assertForbidden();
    }

    public function test_mobile_money_is_shown_per_network_like_banks(): void
    {
        $today = CarbonImmutable::today();
        $loan = $this->serviceLoan($this->admin, amount: 100000, legacyInsurance: 0);
        $loan->schedules()->update(['due_date' => $today->toDateString()]);

        $loans = app(LoanService::class);
        $mpesa = $loans->deposit($loan->fresh(), 5000, $today, 'MNO', $this->admin);
        $this->paidThroughBank($loan, $mpesa, 'M-Pesa', 'MNO');
        $loans->deposit($loan->fresh(), 3000, $today, 'AIRTEL', $this->admin);

        $data = $this->actingAs($this->finance)->getJson('/api/v1/dashboard/finance?month='.$today->format('Y-m'))->assertOk()->json('data');

        $channels = collect($data['channels'])->keyBy('label');
        $this->assertEquals(5000, $channels['M-PESA']['collected'], 'Mobile money is shown under the network that received it.');
        $this->assertSame('mobile', $channels['M-PESA']['channel']);
        $this->assertEquals(3000, $channels['AIRTEL MONEY']['collected'], 'A repayment recorded by network method is shown under that network.');
        $this->assertArrayNotHasKey('Mobile Money', $channels->all());
    }

    private function paidThroughBank(Loan $loan, LoanTransaction $repayment, string $bank, string $channel = 'BANK'): void
    {
        $payment = Payment::create([
            'company_id' => $loan->company_id, 'branch_id' => $loan->branch_id, 'customer_id' => $loan->customer_id, 'loan_id' => $loan->id,
            'source' => Payment::SOURCE_MANUAL, 'channel' => $channel, 'provider' => $bank, 'reference' => 'REF-'.$repayment->id,
            'amount' => $repayment->amount, 'allocated_amount' => $repayment->amount, 'status' => 'allocated', 'paid_on' => $repayment->transaction_date,
        ]);
        PaymentAllocation::create(['payment_id' => $payment->id, 'loan_id' => $loan->id, 'loan_transaction_id' => $repayment->id, 'amount' => $repayment->amount]);
    }
}

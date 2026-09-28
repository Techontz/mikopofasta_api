<?php

namespace Tests\Feature\Api\Reports;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\WriteOff;
use App\Services\Ledger;
use App\Services\LoanService;
use App\Services\Reports\LoanBalances;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LiveReportsApiTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-13 10:00:00');
        $this->admin = $this->signInAdmin();
    }

    /**
     * @return array<string, array{0: string, 1?: array<string, mixed>}>
     */
    public static function endpoints(): array
    {
        return [
            'cash' => ['api.v1.reports.cash'],
            'branchwise' => ['api.v1.reports.branchwise'],
            'file' => ['api.v1.reports.file'],
            'new loans' => ['api.v1.reports.file.new-loans'],
            'pending' => ['api.v1.reports.pending'],
            'repayment' => ['api.v1.reports.repayment'],
            'default' => ['api.v1.reports.default'],
            'write off' => ['api.v1.reports.write-off'],
            'collection' => ['api.v1.reports.collection'],
            'statement' => ['api.v1.reports.statement'],
            'receivable' => ['api.v1.reports.receivable'],
            'received' => ['api.v1.reports.received'],
            'daily' => ['api.v1.reports.daily'],
            'development' => ['api.v1.reports.development'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_every_live_report_requires_reports_view(string $route): void
    {
        $this->getJson(route($route))->assertOk();

        $this->actingAs($this->employeeWithRole('loan_officer'));
        $this->getJson(route($route))->assertForbidden();
    }

    public function test_report_balances_match_loan_service_outstanding_under_principal_penalty_interest_order(): void
    {
        $loan = $this->cashedOutLoan(daysAgo: 10);
        $this->penalise($loan, 10000);
        $this->repay($loan, 105000);

        $balances = LoanBalances::join(Loan::query()->whereKey($loan->id))->first();
        $expected = app(LoanService::class)->outstanding($loan->fresh());

        $this->assertSame(['principal' => 0.0, 'penalty' => 5000.0, 'interest' => 30000.0, 'insurance' => 0.0, 'total' => 35000.0], $expected);
        foreach (['principal', 'penalty', 'interest', 'insurance', 'total'] as $component) {
            $this->assertEqualsWithDelta($expected[$component], (float) $balances->{"out_{$component}"}, 0.001, $component);
        }

        $row = $this->getJson(route('api.v1.reports.collection'))->assertOk()->json('data.rows.0');
        $this->assertEquals(105000, $row['paid']);
        $this->assertEquals(30000, $row['remain']);
        $this->assertEquals(5000, $row['penalty']);
        $this->assertEquals(['principal' => 0, 'penalty' => 5000, 'interest' => 30000, 'insurance' => 0, 'total' => 35000], $row['outstanding']);
    }

    public function test_cash_transactions_default_to_today_filter_by_branch_and_total(): void
    {
        $loan = $this->cashedOutLoan(daysAgo: 7);
        $this->repay($loan, 130000);
        $other = $this->otherBranch();

        $today = $this->getJson(route('api.v1.reports.cash'))->assertOk();
        $today->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.customer', $loan->customer->full_name)
            ->assertJsonPath('data.totals.deposit', 130000)
            ->assertJsonPath('data.filter.from', '2026-09-13');

        $this->getJson(route('api.v1.reports.cash', ['branch_id' => 'all', 'from' => '2026-09-01', 'to' => '2026-09-13']))
            ->assertJsonCount(2, 'data.rows')
            ->assertJsonPath('data.totals.withdrawal', 100000);

        $this->getJson(route('api.v1.reports.cash', ['branch_id' => $other->id, 'from' => '2026-09-01', 'to' => '2026-09-13']))
            ->assertJsonCount(0, 'data.rows');
    }

    public function test_branchwise_summary_splits_receivable_and_received(): void
    {
        $loan = $this->cashedOutLoan(daysAgo: 7);
        $this->penalise($loan, 4000);
        $this->repay($loan, 50000);

        $data = $this->getJson(route('api.v1.reports.branchwise'))->assertOk()->json('data');
        $row = collect($data['rows'])->firstWhere('branch_id', $this->admin->branch_id);

        $this->assertEquals(130000, $row['receivable']);
        $this->assertEquals(100000, $row['receivable_principal']);
        $this->assertEquals(30000, $row['receivable_interest']);
        $this->assertEquals(50000, $row['received']);
        $this->assertEquals(50000, $row['received_principal']);
        $this->assertEquals(0, $row['received_interest']);
        $this->assertEquals(80000, $row['pending']);
        $this->assertEquals(130000, $data['totals']['receivable']);

        $dated = $this->getJson(route('api.v1.reports.branchwise', ['from' => '2026-01-01', 'to' => '2026-01-31']))->json('data.totals');
        $this->assertEquals(0, $dated['receivable']);
    }

    public function test_file_report_has_a_column_per_collection_month_and_new_loans(): void
    {
        $loan = $this->cashedOutLoan(daysAgo: 20);
        $this->repay($loan, 50000, daysAgo: 15);
        $this->repay($loan, 30000);

        $data = $this->getJson(route('api.v1.reports.file', ['year' => 2026, 'loan_status' => 'ALL']))->assertOk()->json('data');

        $this->assertSame([['number' => 8, 'name' => 'August'], ['number' => 9, 'name' => 'September']], $data['months']);
        $this->assertEquals(['8' => 50000, '9' => 30000], $data['rows'][0]['months']);
        $this->assertEquals(50000, $data['rows'][0]['remain']);
        $this->assertEquals(30000, $data['totals']['month_9']);
        $this->assertContains(2026, $data['years']);

        $this->getJson(route('api.v1.reports.file', ['year' => 2025]))->assertJsonCount(0, 'data.rows');
        $this->getJson(route('api.v1.reports.file.new-loans', ['year' => 2026]))->assertJsonPath('data.rows.0.id', $loan->id);
    }

    public function test_pending_repayment_and_default_reports(): void
    {
        $overdue = $this->cashedOutLoan(daysAgo: 10);
        $this->repay($overdue, 30000, daysAgo: 1);
        $defaulted = $this->cashedOutLoan(daysAgo: 40);
        $defaulted->update(['status' => LoanStatus::Default]);
        $this->repay($defaulted, 20000);

        $this->getJson(route('api.v1.reports.pending'))->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.id', $overdue->id)
            ->assertJsonPath('data.rows.0.pending', 100000)
            ->assertJsonPath('data.rows.0.date', '2026-09-10');

        $repayment = $this->getJson(route('api.v1.reports.repayment'))->json('data');
        $this->assertCount(2, $repayment['rows']);
        $this->assertEquals(200000, $repayment['totals']['amount_approved']);
        $this->assertEquals(260000, $repayment['totals']['total_payable']);

        $this->getJson(route('api.v1.reports.default'))
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.id', $defaulted->id)
            ->assertJsonPath('data.rows.0.paid_this_month', 20000)
            ->assertJsonPath('data.rows.0.remain', 110000)
            ->assertJsonPath('data.totals.remain', 110000);
    }

    public function test_write_off_lists_open_and_recovered_debts(): void
    {
        $open = $this->cashedOutLoan(daysAgo: 30);
        $recovered = $this->cashedOutLoan(daysAgo: 30);
        WriteOff::create(['loan_id' => $open->id, 'amount' => 130000, 'written_off_on' => '2026-09-01']);
        WriteOff::create(['loan_id' => $recovered->id, 'amount' => 90000, 'recovered_amount' => 90000, 'written_off_on' => '2026-09-01']);

        $this->getJson(route('api.v1.reports.write-off'))->assertOk()
            ->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.loan_id', $open->id)->assertJsonPath('data.totals.amount', 130000);
        $this->getJson(route('api.v1.reports.write-off', ['done' => 1]))
            ->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.loan_id', $recovered->id)->assertJsonPath('data.totals.recovered_amount', 90000);
    }

    public function test_collection_filters_by_live_status(): void
    {
        $active = $this->cashedOutLoan(daysAgo: 3);
        $pending = Loan::factory()->create(['customer_id' => Customer::factory()->create(['branch_id' => $this->admin->branch_id])->id]);

        $this->getJson(route('api.v1.reports.collection'))->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.id', $active->id);
        $this->getJson(route('api.v1.reports.collection', ['loan_status' => 'PENDING']))->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.id', $pending->id);
        $this->getJson(route('api.v1.reports.collection', ['loan_status' => 'WRONG']))->assertUnprocessable();
    }

    public function test_statement_reuses_the_payment_statement_split_for_one_loan(): void
    {
        $loan = $this->cashedOutLoan(daysAgo: 7);
        $other = $this->cashedOutLoan(daysAgo: 7);
        $this->penalise($loan, 10000);
        $this->repay($loan, 105000);

        $this->getJson(route('api.v1.reports.statement'))->assertOk()->assertJsonPath('data.customer', null);

        $data = $this->getJson(route('api.v1.reports.statement', ['customer_id' => $loan->customer_id, 'loan_id' => $loan->id]))->assertOk()->json('data');

        $this->assertCount(2, $data['rows']);
        $this->assertSame('LOAN RETURN', $data['rows'][1]['description']);
        $this->assertEquals([100000, 5000, 0], [$data['rows'][1]['principal'], $data['rows'][1]['penalty'], $data['rows'][1]['interest']]);
        $this->assertEquals(105000, $data['rows'][1]['balance']);
        $this->assertEquals(35000, $data['loan']['outstanding']['total']);
        $this->assertEquals(105000, $data['totals']['deposit']);
        $this->assertSame((string) $loan->id, $data['loans'][0]['value']);

        $this->getJson(route('api.v1.reports.statement', ['customer_id' => $loan->customer_id, 'loan_id' => $other->id]))->assertNotFound();
    }

    public function test_receivable_and_received_reports(): void
    {
        $loan = $this->cashedOutLoan(daysAgo: 7);

        $this->getJson(route('api.v1.reports.receivable'))->assertOk()
            ->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.is_paid', false)->assertJsonPath('data.totals.pending', 130000);

        $this->repay($loan, 130000);

        $this->getJson(route('api.v1.reports.receivable', ['paid_status' => 'not paid']))->assertJsonCount(0, 'data.rows');
        $this->getJson(route('api.v1.reports.receivable', ['paid_status' => 'paid']))->assertJsonCount(1, 'data.rows');
        $this->getJson(route('api.v1.reports.received'))
            ->assertJsonPath('data.rows.0.principal', 100000)
            ->assertJsonPath('data.rows.0.interest', 30000)
            ->assertJsonPath('data.rows.0.reserve', 6000)
            ->assertJsonPath('data.totals.amount', 130000);
    }

    public function test_daily_report_opening_equals_previous_closing_and_ignores_non_cash_assets(): void
    {
        $loan = $this->cashedOutLoan(daysAgo: 7);
        $this->repay($loan, 130000);

        $today = $this->getJson(route('api.v1.reports.daily'))->assertOk()->json('data');
        $yesterday = $this->getJson(route('api.v1.reports.daily', ['from' => '2026-09-12', 'to' => '2026-09-12']))->json('data');

        $this->assertSame('September, 13, 2026', $today['heading']);
        $this->assertEquals(130000, $today['in']['DEPOSIT']);
        $this->assertEquals($yesterday['closing'], $today['opening']);
        $this->assertEqualsWithDelta($today['opening'] + 130000, $today['closing'], 0.01);

        app(Ledger::class)->journal($this->admin->company_id, 'TOP-UP OFFSET', [
            ['account' => Account::Offset, 'branch' => $this->admin->branch_id, 'debit' => 5000],
            ['account' => Account::Principal, 'branch' => $this->admin->branch_id, 'credit' => 5000],
        ], $loan);

        $after = $this->getJson(route('api.v1.reports.daily'))->json('data');
        $this->assertEqualsWithDelta($today['closing'] - 5000, $after['closing'], 0.01);
    }

    public function test_customer_development_lists_marked_customers_and_their_loans(): void
    {
        $loan = $this->cashedOutLoan(daysAgo: 7);
        $loan->customer->update(['is_marked' => true]);
        $this->penalise($loan, 2000);
        $this->cashedOutLoan(daysAgo: 7);

        $this->getJson(route('api.v1.reports.development'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $loan->customer_id);

        $this->getJson(route('api.v1.reports.development.show', $loan->customer_id))->assertOk()
            ->assertJsonPath('data.summary.remain', 130000)
            ->assertJsonPath('data.summary.penalty', 2000)
            ->assertJsonPath('data.loans.0.loan_number', $loan->loan_number);

        $this->getJson(route('api.v1.reports.development.show', Customer::factory()->create()->id))->assertNotFound();
    }

    public function test_branch_scoped_employees_only_see_their_branch(): void
    {
        $mine = $this->cashedOutLoan(daysAgo: 7);
        $other = $this->otherBranch();
        $theirs = $this->cashedOutLoan(daysAgo: 7, branch: $other);

        $this->actingAs($this->employeeWithRole('branch_manager', $other->id));

        $this->getJson(route('api.v1.reports.repayment'))->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.id', $theirs->id);
        $this->getJson(route('api.v1.reports.repayment', ['branch_id' => 'all']))->assertJsonCount(1, 'data.rows');
        $this->getJson(route('api.v1.reports.branchwise'))->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.branch_id', $other->id);
        $this->getJson(route('api.v1.reports.repayment', ['branch_id' => $mine->branch_id]))->assertForbidden();
        $this->getJson(route('api.v1.reports.statement', ['customer_id' => $mine->customer_id]))->assertForbidden();
        $this->getJson(route('api.v1.reports.development.show', $mine->customer_id))->assertNotFound();
    }
}

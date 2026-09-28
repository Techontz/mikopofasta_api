<?php

namespace Tests\Feature\Api\Reports;

use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanMandate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fixture (today 2026-09-13, weekly single-instalment loans of 100,000 + 30,000 interest, 5,000 fee):
 *  A  branch 1, female 30  — cashed out 20 days ago, due 13 days ago, paid in full 10 days ago (3 days late)  → CLOSED
 *  B  branch 1, male 50    — cashed out 14 days ago, due 7 days ago, paid in full 8 days ago (1 day early)    → CLOSED
 *  C  branch 1, male 22    — cashed out 60 days ago, due 53 days ago, DEFAULT, 20,000 recovered 5 days ago
 *  D  branch 1, female 40  — cashed out 2 days ago, 10,000 penalty, 105,000 paid today (→ P 100,000, Pen 5,000, I 0)
 *  E  branch 2, male 65    — 200,000 cashed out 3 days ago, nothing paid
 */
class PortfolioReportsApiTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    /**
     * @var array<string, Loan>
     */
    private array $loans = [];

    private Branch $secondBranch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-13 10:00:00');
        $this->admin = $this->signInAdmin();
        $this->secondBranch = $this->otherBranch();
        $born = fn (int $age): string => CarbonImmutable::today()->subYears($age)->subDays(10)->toDateString();

        $this->loans['A'] = $this->cashedOutLoan(daysAgo: 20, customer: ['gender' => 'female', 'date_of_birth' => $born(30)]);
        $this->repay($this->loans['A'], 130000, daysAgo: 10);

        $this->loans['B'] = $this->cashedOutLoan(daysAgo: 14, customer: ['gender' => 'male', 'date_of_birth' => $born(50)]);
        $this->repay($this->loans['B'], 130000, daysAgo: 8);

        $this->loans['C'] = $this->cashedOutLoan(daysAgo: 60, customer: ['gender' => 'male', 'date_of_birth' => $born(22)]);
        $this->loans['C']->update(['status' => LoanStatus::Default]);
        $this->repay($this->loans['C'], 20000, daysAgo: 5);

        $this->loans['D'] = $this->cashedOutLoan(daysAgo: 2, customer: ['gender' => 'female', 'date_of_birth' => $born(40)]);
        $this->penalise($this->loans['D'], 10000);
        $this->repay($this->loans['D'], 105000);

        $this->loans['E'] = $this->cashedOutLoan(daysAgo: 3, branch: $this->secondBranch, customer: ['gender' => 'male', 'date_of_birth' => $born(65)], principal: 200000);

        foreach (['D' => LoanMandate::ACTIVE, 'E' => LoanMandate::FAILED] as $key => $status) {
            LoanMandate::create([
                'company_id' => $this->admin->company_id, 'loan_id' => $this->loans[$key]->id, 'bank_name' => 'CRDB',
                'account_name' => 'TEST', 'account_number' => '0150000000', 'status' => $status,
            ]);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function endpoints(): array
    {
        return [
            'portfolio' => ['api.v1.reports.portfolio'],
            'collections' => ['api.v1.reports.collections'],
            'arrears' => ['api.v1.reports.arrears'],
            'recovery' => ['api.v1.reports.recovery'],
            'behaviour' => ['api.v1.reports.behaviour'],
            'segmentation' => ['api.v1.reports.segmentation'],
            'age analysis' => ['api.v1.reports.age-analysis'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_portfolio_reports_require_reports_view_and_respect_branch_scope(string $route): void
    {
        $this->getJson(route($route))->assertOk();

        $this->actingAs($this->employeeWithRole('branch_manager', $this->secondBranch->id));
        $this->getJson(route($route))->assertOk();
        $this->getJson(route($route, ['branch_id' => $this->admin->branch_id]))->assertForbidden();

        $this->actingAs($this->employeeWithRole('loan_officer'));
        $this->getJson(route($route))->assertForbidden();
    }

    public function test_loan_portfolio_uses_outstanding_balances_after_allocation(): void
    {
        $data = $this->getJson(route('api.v1.reports.portfolio'))->assertOk()->json('data');

        $this->assertEquals([
            'issued_count' => 5, 'issued_amount' => 600000, 'active_count' => 2, 'overdue_count' => 0, 'completed_count' => 2,
            'default_count' => 1, 'written_off_count' => 0, 'active_customers' => 3, 'outstanding_principal' => 280000,
            'outstanding_interest' => 120000, 'outstanding_penalty' => 5000, 'outstanding_insurance' => 0, 'outstanding_total' => 405000, 'written_off_principal' => 0, 'written_off_outstanding' => 0,
            'penalty_without_loan' => 0, 'active_outstanding_principal' => 200000, 'default_outstanding_principal' => 80000, 'salary_advance_outstanding' => 0,
            'customer_outstanding' => 405000, 'old_system_loans' => 0, 'old_system_outstanding_principal' => 0,
        ], $data['summary']);

        $branch = collect($data['by_branch'])->firstWhere('outstanding_principal', 80000);
        $this->assertSame(4, $branch['loans']);
        $this->assertEquals(28.6, $branch['share']);
        $this->assertCount(1, $data['by_officer']);
        $this->assertEquals(280000, $data['by_officer'][0]['outstanding_principal']);

        $scoped = $this->getJson(route('api.v1.reports.portfolio', ['branch_id' => $this->secondBranch->id]))->json('data.summary');
        $this->assertSame(1, $scoped['issued_count']);
        $this->assertEquals(200000, $scoped['outstanding_principal']);

        $this->getJson(route('api.v1.reports.portfolio', ['from' => '2026-09-10', 'to' => '2026-09-13']))->assertJsonPath('data.summary.issued_count', 2);
    }

    public function test_repayment_expected_vs_actual_and_mandate_success_rate(): void
    {
        $data = $this->getJson(route('api.v1.reports.collections', ['from' => '2026-08-24', 'to' => '2026-09-13', 'period' => 'monthly']))->assertOk()->json('data');

        $this->assertEquals(260000, $data['summary']['expected']);
        $this->assertEquals(385000, $data['summary']['collected']);
        $this->assertEquals(5000, $data['summary']['penalty']);
        $this->assertEquals(146.2, $data['summary']['collection_rate']);
        $this->assertSame(['2026-08', '2026-09'], array_column($data['rows'], 'label'));
        $this->assertEquals(['total' => 2, 'active' => 1, 'failed' => 1, 'pending' => 0, 'success_rate' => 50], $data['mandates']);

        $september = $this->getJson(route('api.v1.reports.collections', ['from' => '2026-09-01', 'to' => '2026-09-13']))->json('data.summary');
        $this->assertEquals(130000, $september['expected']);
        $this->assertEquals(130000, $september['due_paid']);
    }

    public function test_arrears_and_par_per_branch(): void
    {
        $data = $this->getJson(route('api.v1.reports.arrears'))->assertOk()->json('data');
        $summary = $data['summary'];

        $this->assertEquals(280000, $summary['portfolio']);
        $this->assertSame(1, $summary['loans_in_arrears']);
        $this->assertEquals(110000, $summary['arrears_amount']);
        $this->assertEquals([80000, 80000, 80000, 0, 0], [$summary['par1'], $summary['par7'], $summary['par30'], $summary['par60'], $summary['par90']]);
        $this->assertEquals(28.6, $summary['par30_rate']);
        $this->assertEquals(20, $summary['default_rate']);

        $this->assertCount(1, $data['rows']);
        $this->assertSame(53, $data['rows'][0]['dpd']);
        $this->assertSame('31–60', $data['rows'][0]['bucket']);

        $firstBranch = collect($data['by_branch'])->firstWhere('par30', 80000);
        $this->assertEquals(100, $firstBranch['par30_rate']);
        $this->assertEquals(25, $firstBranch['default_rate']);
    }

    public function test_recovery_from_defaults(): void
    {
        $data = $this->getJson(route('api.v1.reports.recovery'))->assertOk()->json('data');

        $this->assertEquals(20000, $data['summary']['recovered_default']);
        $this->assertEquals(110000, $data['summary']['default_balance']);
        $this->assertEquals(15.4, $data['summary']['efficiency']);
        $this->assertCount(1, $data['rows']);
        $this->assertSame(48, $data['rows'][0]['days_after_end']);
        $this->assertEquals(20000, $data['rows'][0]['principal']);

        $this->getJson(route('api.v1.reports.recovery', ['from' => '2026-09-10', 'to' => '2026-09-13']))->assertJsonPath('data.summary.recovered_default', 0);
    }

    public function test_repayment_behaviour_dpd_buckets_and_customer_rating(): void
    {
        $data = $this->getJson(route('api.v1.reports.behaviour'))->assertOk()->json('data');

        $rows = collect($data['rows'])->keyBy('loan_id');
        $this->assertSame(['2026-09-03', 3, '1–7'], [$rows[$this->loans['A']->id]['paid_date'], $rows[$this->loans['A']->id]['delay_days'], $rows[$this->loans['A']->id]['bucket']]);
        $this->assertSame([-1, '0'], [$rows[$this->loans['B']->id]['delay_days'], $rows[$this->loans['B']->id]['bucket']]);
        $this->assertSame([null, 53, '31–60'], [$rows[$this->loans['C']->id]['paid_date'], $rows[$this->loans['C']->id]['delay_days'], $rows[$this->loans['C']->id]['bucket']]);
        $this->assertCount(3, $data['rows']);

        $buckets = collect($data['buckets'])->pluck('instalments', 'bucket')->all();
        $this->assertSame(['0' => 1, '1–7' => 1, '8–30' => 0, '31–60' => 1, '61–90' => 0, '90+' => 0], $buckets);

        $customers = collect($data['customers'])->keyBy('customer_id');
        $this->assertSame(['B', 'Late payer'], [$customers[$this->loans['A']->customer_id]['rating'], $customers[$this->loans['A']->customer_id]['pattern']]);
        $this->assertSame(['A', 'Early payer'], [$customers[$this->loans['B']->customer_id]['rating'], $customers[$this->loans['B']->customer_id]['pattern']]);
        $this->assertSame(['D', 'Chronic defaulter'], [$customers[$this->loans['C']->customer_id]['rating'], $customers[$this->loans['C']->customer_id]['pattern']]);
        $this->assertSame(['A' => 1, 'B' => 1, 'C' => 0, 'D' => 1], $data['ratings']);
    }

    public function test_customer_segmentation_and_age_analysis(): void
    {
        $dimensions = $this->getJson(route('api.v1.reports.segmentation'))->assertOk()->json('data.dimensions');

        $gender = collect($dimensions['gender'])->keyBy('segment');
        $this->assertSame(2, $gender['Female']['customers']);
        $this->assertEquals([200000, 235000, 0, 45000], [$gender['Female']['disbursed'], $gender['Female']['collected'], $gender['Female']['default_rate'], $gender['Female']['profit']]);
        $this->assertSame(3, $gender['Male']['loans']);
        $this->assertEquals(33.3, $gender['Male']['default_rate']);
        $this->assertEquals(26, $gender['Male']['avg_delay_days']);

        $sizes = collect($dimensions['loan_size'])->pluck('loans', 'segment')->all();
        $this->assertSame(['Below 500,000' => 5], $sizes);
        $this->assertSame(['category', 'gender', 'age', 'occupation', 'region', 'branch', 'loan_size'], array_keys($dimensions));

        $ages = collect($this->getJson(route('api.v1.reports.age-analysis'))->assertOk()->json('data.rows'))->keyBy('segment');
        $this->assertSame(['18–25', '26–35', '36–45', '46–60', '60+'], $ages->keys()->all());
        $this->assertEquals([1, 100, 0, 3], [$ages['26–35']['customers'], $ages['26–35']['repayment_rate'], $ages['26–35']['default_rate'], $ages['26–35']['avg_delay_days']]);
        $this->assertEquals([15.4, 100, 53], [$ages['18–25']['repayment_rate'], $ages['18–25']['default_rate'], $ages['18–25']['avg_delay_days']]);
        $this->assertSame(1, $ages['60+']['loans']);
        $this->assertEquals(200000, $ages['60+']['disbursed']);
    }
}

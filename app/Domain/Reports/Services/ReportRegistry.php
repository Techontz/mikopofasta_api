<?php

declare(strict_types=1);

namespace App\Domain\Reports\Services;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\Reports\AgeAnalysisReport;
use App\Domain\Reports\Reports\ArrearsReport;
use App\Domain\Reports\Reports\AuditTrailReport;
use App\Domain\Reports\Reports\BranchEfficiencyReport;
use App\Domain\Reports\Reports\BranchPnlReport;
use App\Domain\Reports\Reports\BranchRankingReport;
use App\Domain\Reports\Reports\CashflowReport;
use App\Domain\Reports\Reports\CommissionReport;
use App\Domain\Reports\Reports\DailyCollectionReport;
use App\Domain\Reports\Reports\DailyDisbursementReport;
use App\Domain\Reports\Reports\ExecutiveSummaryReport;
use App\Domain\Reports\Reports\FinancialStatementsReport;
use App\Domain\Reports\Reports\HqCashflowReport;
use App\Domain\Reports\Reports\PayrollReport;
use App\Domain\Reports\Reports\PerformanceReport;
use App\Domain\Reports\Reports\PortfolioReport;
use App\Domain\Reports\Reports\RecoveryReport;
use App\Domain\Reports\Reports\RepaymentBehaviorReport;
use App\Domain\Reports\Reports\RepaymentReport;
use App\Domain\Reports\Reports\ReversalsReport;
use App\Domain\Reports\Reports\SegmentationReport;
use App\Domain\Reports\Reports\SuspenseReport;
use App\Domain\Reports\Reports\TrialBalanceReport;
use App\Domain\Reports\Reports\ZoneCommissionReport;

/**
 * Every report the system publishes, keyed by slug.
 *
 * The twenty-one §15.6 names, in §15.6's order, plus three Phase 8 asks for
 * that §15.6's list omits — Trial Balance, Staff Performance and the Executive
 * Summary. Each of those three is marked below with why it is not an invention:
 * two read tables the specification already defines, and the third only
 * republishes figures the other reports compute.
 *
 * The registry is the single source of what exists. `GET /reports` enumerates
 * it, `GET /reports/{slug}` resolves through it, and the report index page and
 * the API can therefore never disagree about which reports there are.
 */
final class ReportRegistry
{
    /** @var array<string, Report>|null */
    private ?array $reports = null;

    public function __construct(private readonly ReportSources $sources) {}

    /**
     * @return array<string, Report>
     */
    public function all(): array
    {
        if ($this->reports !== null) {
            return $this->reports;
        }

        $cashflow = new CashflowReport($this->sources);

        /** @var list<Report> $reports */
        $reports = [
            // §15.6, in the order that section lists them.
            new PortfolioReport($this->sources),
            new RepaymentReport($this->sources),
            new ArrearsReport($this->sources),
            new RecoveryReport($this->sources),
            $cashflow,
            new BranchPnlReport($this->sources),
            new BranchEfficiencyReport($this->sources),
            new HqCashflowReport($cashflow, $this->sources),
            new PayrollReport,
            new CommissionReport,
            new ZoneCommissionReport,
            new FinancialStatementsReport($this->sources),
            new AuditTrailReport,
            new SuspenseReport($this->sources),
            new ReversalsReport,
            new DailyCollectionReport($this->sources),
            new DailyDisbursementReport($this->sources),
            new BranchRankingReport($this->sources),
            new SegmentationReport($this->sources),
            new AgeAnalysisReport($this->sources),
            new RepaymentBehaviorReport($this->sources),

            // Named by Phase 8, absent from §15.6's list. The trial balance is
            // the same computation the Financial Statements report and
            // GET /ledger/trial-balance already run; the performance report
            // reads §2.9's staff_performance_records.
            new TrialBalanceReport($this->sources),
            new PerformanceReport,
        ];

        $keyed = [];

        foreach ($reports as $report) {
            $keyed[$report->slug()] = $report;
        }

        /*
         * Built last and handed the others: the executive summary is a
         * composition of their published summaries, not a calculation of its
         * own, so it cannot exist before them.
         */
        $executive = new ExecutiveSummaryReport($keyed);
        $keyed[$executive->slug()] = $executive;

        return $this->reports = $keyed;
    }

    public function find(string $slug): ?Report
    {
        return $this->all()[$slug] ?? null;
    }

    /**
     * The catalogue `GET /reports` publishes.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(): array
    {
        return array_values(array_map(static fn (Report $r): array => [
            'slug' => $r->slug(),
            'title' => $r->title(),
            'description' => $r->description(),
            'group' => $r->group(),
            'filters' => $r->supportedFilters(),
        ], $this->all()));
    }
}

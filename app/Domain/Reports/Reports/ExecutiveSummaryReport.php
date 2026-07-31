<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;

/**
 * `GET /reports/executive-summary` — the executive dashboard, as one report.
 *
 * Phase 8 names an executive dashboard; the frontend defines none. Its
 * `/dashboard` page is explicitly a foundation shell ("business modules land in
 * their own implementation phases") and shows branch and permission counts
 * rather than business metrics.
 *
 * So this invents no metric. **Every figure is taken from another report's own
 * summary**, by running that report and reading the line it already publishes.
 * The consequence is that an executive figure can never disagree with the
 * report a manager would drill into to question it — because it IS that
 * report's figure, not a parallel calculation of the same idea.
 *
 * The `source` column names which report each line came from, so a reader can
 * go and check.
 */
final class ExecutiveSummaryReport implements Report
{
    /**
     * @param array<string, Report> $reports keyed by slug — the registry
     *                                       passes the built reports in.
     */
    public function __construct(private readonly array $reports) {}

    public function slug(): string
    {
        return 'executive-summary';
    }

    public function title(): string
    {
        return 'Executive Summary';
    }

    public function description(): string
    {
        return 'Headline figures across portfolio, collections, disbursement, branch performance and the ledger.';
    }

    public function group(): string
    {
        return 'Financial';
    }

    public function supportedFilters(): array
    {
        return ['branchId', 'from', 'to', 'period'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $rows = [];

        foreach ($this->sourceReports() as $slug => $wanted) {
            $report = $this->reports[$slug] ?? null;

            if ($report === null) {
                continue;
            }

            // Each source report is asked only for the filters it declares —
            // the same discipline the controller applies, so a figure is never
            // computed under a window the report does not honour.
            $result = $report->compute($filters->only($report->supportedFilters()));

            foreach ($result->summary as $line) {
                if (! in_array($line['label'], $wanted, true)) {
                    continue;
                }

                $rows[] = [
                    'metric' => $line['label'],
                    'value' => $line['value'],
                    'source' => $report->title(),
                    'sourceSlug' => $report->slug(),
                ];
            }
        }

        return new ReportResult(
            columns: [
                ReportColumn::text('metric', 'Metric'),
                ReportColumn::text('value', 'Value'),
                ReportColumn::text('source', 'Source Report'),
                ReportColumn::text('sourceSlug', 'Slug'),
            ],
            rows: $rows,
            summary: [
                ['label' => 'Metrics', 'value' => (string) count($rows)],
                ['label' => 'Source Reports', 'value' => (string) count(array_unique(array_column($rows, 'sourceSlug')))],
            ],
            emptyMessage: 'No activity to summarise for these filters.',
            reconciliation: 'Every figure here is lifted verbatim from the summary of the report named in the Source column — nothing is recomputed. Drilling into that report will always show the same number, because it is the same number.',
        );
    }

    /**
     * Which figure to lift from which report.
     *
     * Deliberately a small, fixed set: an executive summary that grew a line
     * per report would stop being a summary.
     *
     * @return array<string, list<string>>
     */
    private function sourceReports(): array
    {
        return [
            'portfolio' => ['Active Loans', 'Outstanding', 'Collected'],
            'age-analysis' => ['Portfolio at Risk (8+ days)', 'PAR Ratio'],
            'arrears' => ['Loans in Arrears', 'Overdue Amount'],
            'daily-collection' => ['Total Collected'],
            'daily-disbursement' => ['Total Disbursed'],
            'branch-pnl' => ['Total Income', 'Total Expense', 'Profit'],
            'trial-balance' => ['Balanced'],
            'suspense' => ['Open Items', 'Open Amount'],
            'payroll' => ['Net Payroll'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Services\ReportSources;

/**
 * `GET /reports/hq-cashflow` — cash movement scoped to Head Office.
 *
 * §12: "HQ is a special Branch record, not a separate table", and an HQ-only
 * view is "the same report scoped `is_head_office=true` — a query variant of
 * one report definition, not two separate report engines". So this delegates
 * to the Cashflow report rather than reimplementing it; the two can never
 * drift because there is only one of them.
 */
final class HqCashflowReport implements Report
{
    public function __construct(
        private readonly CashflowReport $cashflow,
        private readonly ReportSources $sources,
    ) {}

    public function slug(): string
    {
        return 'hq-cashflow';
    }

    public function title(): string
    {
        return 'HQ Cashflow';
    }

    public function description(): string
    {
        return 'Cash movement scoped to the Head Office branch.';
    }

    public function group(): string
    {
        return 'Financial';
    }

    /**
     * No `branchId`: the branch is not the caller's to choose. Offering it
     * would let "HQ cashflow" return a different branch's figures.
     */
    public function supportedFilters(): array
    {
        return ['from', 'to', 'period'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $hq = $this->sources->headOffice();

        if ($hq === null) {
            return new ReportResult(
                columns: [],
                rows: [],
                emptyMessage: 'No Head Office branch is configured.',
                reconciliation: 'HQ is identified by branches.is_head_office (§12); no branch carries that flag.',
            );
        }

        $scoped = $this->cashflow->compute($filters->forBranch((int) $hq->getKey()));

        return new ReportResult(
            columns: $scoped->columns,
            rows: $scoped->rows,
            totals: $scoped->totals,
            summary: $scoped->summary,
            emptyMessage: 'No cash movement at Head Office in this window.',
            reconciliation: sprintf(
                '%s Scoped to %s via branches.is_head_office — the same report definition as branch cashflow, not a separate engine (§12).',
                $scoped->reconciliation,
                $hq->name,
            ),
        );
    }
}

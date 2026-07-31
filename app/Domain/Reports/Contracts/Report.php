<?php

declare(strict_types=1);

namespace App\Domain\Reports\Contracts;

use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;

/**
 * One report — §15.6.
 *
 * Every report is a **read-model**: it computes from the operational tables
 * and the ledger on every call and stores nothing of its own. There is no
 * reporting database, no nightly rollup table and no report-only column
 * anywhere in the schema. That is the point of §15.6's closing line — numbers
 * on screen are "traceable to a specific computation timestamp" — and it is
 * what makes a report incapable of disagreeing with the module it summarises.
 */
interface Report
{
    /** The URL segment: `/reports/{slug}`. */
    public function slug(): string;

    public function title(): string;

    public function description(): string;

    /** Portfolio | Collections | Financial | Branch | HR | Compliance. */
    public function group(): string;

    /**
     * Which of `branchId`, `period`, `from`, `to` this report honours.
     *
     * Declared rather than assumed: a report that ignores `from`/`to` must not
     * echo them back in `filters_applied`, or the caller would believe a
     * window was applied that never was.
     *
     * @return list<string>
     */
    public function supportedFilters(): array;

    public function compute(ReportFilters $filters): ReportResult;
}

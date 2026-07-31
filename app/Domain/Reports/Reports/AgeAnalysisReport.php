<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Enums\DpdBucket;
use App\Domain\Reports\Services\ReportSources;
use App\Models\Loan;
use App\Support\Money;

/**
 * `GET /reports/age-analysis` — the portfolio split across days-past-due
 * buckets, and Portfolio at Risk.
 *
 * PAR is the headline figure: outstanding sitting in the 8–30 and 30+ buckets.
 * Every loan appears in exactly one bucket, so the buckets sum to the same
 * total the Loan Portfolio report shows — a test asserts that rather than
 * trusting it.
 */
final class AgeAnalysisReport implements Report
{
    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'age-analysis';
    }

    public function title(): string
    {
        return 'Age Analysis';
    }

    public function description(): string
    {
        return 'Portfolio split across days-past-due buckets.';
    }

    public function group(): string
    {
        return 'Collections';
    }

    public function supportedFilters(): array
    {
        return ['branchId'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $loans = $this->sources->openBookLoans($filters);
        $total = Money::sum($loans->map(fn (Loan $l): Money => $this->sources->loanOutstanding($l)));

        $rows = [];
        $atRisk = Money::zero();

        foreach (DpdBucket::cases() as $bucket) {
            $inBucket = $loans->filter(
                fn (Loan $l): bool => $this->sources->bucketFor($this->sources->daysPastDue($l)) === $bucket,
            );

            $amount = Money::sum($inBucket->map(fn (Loan $l): Money => $this->sources->loanOutstanding($l)));

            $rows[] = [
                'bucket' => $bucket->label(),
                'loans' => $inBucket->count(),
                'outstanding' => $amount->toDecimalString(),
                'share' => $this->sources->percentageOf($amount, $total),
            ];

            if ($bucket->isAtRisk()) {
                $atRisk = $atRisk->add($amount);
            }
        }

        return new ReportResult(
            columns: [
                ReportColumn::text('bucket', 'Bucket'),
                ReportColumn::number('loans', 'Loans'),
                ReportColumn::money('outstanding', 'Outstanding'),
                ReportColumn::percent('share', 'Share'),
            ],
            rows: $rows,
            totals: [
                'bucket' => 'Total',
                'loans' => $loans->count(),
                'outstanding' => $total->toDecimalString(),
                'share' => $total->isPositive() ? '100.000' : '0.000',
            ],
            summary: [
                ['label' => 'Portfolio at Risk (8+ days)', 'value' => $atRisk->toDecimalString()],
                ['label' => 'Total Outstanding', 'value' => $total->toDecimalString()],
                ['label' => 'PAR Ratio', 'value' => $this->sources->percentageOf($atRisk, $total)],
            ],
            emptyMessage: 'No disbursed loans to age.',
            reconciliation: 'Every open-book loan falls in exactly one bucket, so bucket outstanding sums to the same portfolio total as the Loan Portfolio report.',
        );
    }
}

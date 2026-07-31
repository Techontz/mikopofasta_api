<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Hr\Enums\PerformanceRating;
use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Services\ReportSources;
use App\Domain\Reports\Support\Cell;
use App\Models\Loan;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * `GET /reports/repayment-behavior` — §15.6's "DPD buckets + A/B/C/D scoring".
 *
 * Scored per CUSTOMER rather than per loan, and on their WORST loan: a
 * borrower who services one loan perfectly while defaulting on another is a
 * defaulting borrower, and averaging the two would hide exactly the person
 * this report exists to surface.
 *
 * Computed on read from `loan_schedules`. §2.10 keeps a `customer_risk_scores`
 * table, but calls it "recomputed by a queued job, not a source of truth" —
 * it is a cached projection of these same inputs, so reading the inputs
 * directly is what makes this report incapable of being stale.
 */
final class RepaymentBehaviorReport implements Report
{
    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'repayment-behavior';
    }

    public function title(): string
    {
        return 'Repayment Behaviour';
    }

    public function description(): string
    {
        return 'Per-customer A/B/C/D scoring derived from days past due and repayment rate.';
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
        $byCustomer = $this->sources->openBookLoans($filters)->groupBy('customer_id');

        $rows = $byCustomer->map(function (Collection $loans): array {
            /** @var Loan $first */
            $first = $loans->first();

            $worstDpd = (int) $loans->map(fn (Loan $l): int => $this->sources->daysPastDue($l))->max();
            $due = Money::sum($loans->map(fn (Loan $l): Money => $this->sources->loanDue($l)));
            $paid = Money::sum($loans->map(fn (Loan $l): Money => $this->sources->loanPaid($l)));

            return [
                'customer' => Cell::text($first->customer?->fullName()),
                'branch' => Cell::text($first->customer?->branch?->name),
                'loans' => $loans->count(),
                'totalDue' => $due->toDecimalString(),
                'paid' => $paid->toDecimalString(),
                'repaidPct' => $this->sources->percentageOf($paid, $due),
                'worstDpd' => $worstDpd,
                'rating' => $this->sources->bucketFor($worstDpd)->rating()->value,
            ];
        })->values();

        // Best-rated first, and within a rating the worst delinquency first —
        // so the top of the D block is the most urgent row in the report.
        $rows = $rows->sortBy([['rating', 'asc'], ['worstDpd', 'desc']])->values()->all();

        $counts = [];

        foreach (PerformanceRating::cases() as $rating) {
            $counts[] = [
                'label' => sprintf('Rating %s', $rating->value),
                'value' => (string) count(array_filter($rows, static fn (array $r): bool => $r['rating'] === $rating->value)),
            ];
        }

        return new ReportResult(
            columns: [
                ReportColumn::text('customer', 'Customer'),
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::number('loans', 'Loans'),
                ReportColumn::money('totalDue', 'Total Due'),
                ReportColumn::money('paid', 'Paid'),
                ReportColumn::percent('repaidPct', 'Repaid'),
                ReportColumn::number('worstDpd', 'Worst DPD'),
                ReportColumn::text('rating', 'Rating'),
            ],
            rows: $rows,
            summary: $counts,
            emptyMessage: 'No customers with a disbursed loan.',
            reconciliation: 'Ratings are computed on read from loan_schedules using the same DPD boundaries as the Age Analysis report; customer_risk_scores is a cached projection of the same inputs (§2.10), never an independent source.',
        );
    }
}

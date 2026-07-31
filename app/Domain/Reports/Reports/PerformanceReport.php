<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Support\Cell;
use App\Models\StaffPerformanceRecord;

/**
 * `GET /reports/performance` — staff performance reviews by period.
 *
 * Not one of §15.6's twenty-one; Phase 8 names it, and §2.9 defines the table
 * it reads. Nothing is invented: `targets_json`, `achieved_json` and `rating`
 * are stored fields, and the achievement rate is the mean of achieved ÷ target
 * across the metrics — the same derivation the frontend uses to pick a rating
 * in `lib/mock-data/staff-performance.ts`.
 *
 * The rating shown is the one the MANAGER recorded, not the one the
 * achievement rate implies. A manager who disagrees with the arithmetic is the
 * point of having a manager (§11), and overriding their judgement with a
 * computed grade would quietly delete it.
 */
final class PerformanceReport implements Report
{
    public function slug(): string
    {
        return 'performance';
    }

    public function title(): string
    {
        return 'Staff Performance';
    }

    public function description(): string
    {
        return 'Recorded targets, achievements and ratings per staff member and period.';
    }

    public function group(): string
    {
        return 'HR';
    }

    public function supportedFilters(): array
    {
        return ['branchId', 'period'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $records = StaffPerformanceRecord::query()
            ->with(['staffProfile.user', 'staffProfile.branch', 'recorder'])
            ->when($filters->period !== null, fn ($q) => $q->where('period', $filters->period))
            ->when(
                $filters->branchId !== null,
                fn ($q) => $q->whereHas('staffProfile', fn ($s) => $s->where('branch_id', $filters->branchId)),
            )
            ->orderByDesc('period')
            ->get();

        $rows = $records->map(fn (StaffPerformanceRecord $record): array => [
            'period' => $record->period,
            'staff' => $record->staffProfile->displayName(),
            'branch' => Cell::text($record->staffProfile->branch?->name),
            'metrics' => count($record->targets_json),
            'achievement' => $this->achievementRate($record),
            'rating' => Cell::text($record->rating?->value),
            'recordedBy' => Cell::text($record->recorder?->name),
        ])->all();

        $rated = static fn (string $grade): int => count(
            array_filter($rows, static fn (array $r): bool => $r['rating'] === $grade),
        );

        return new ReportResult(
            columns: [
                ReportColumn::text('period', 'Period'),
                ReportColumn::text('staff', 'Staff'),
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::number('metrics', 'Metrics'),
                ReportColumn::percent('achievement', 'Achievement'),
                ReportColumn::text('rating', 'Rating'),
                ReportColumn::text('recordedBy', 'Reviewed by'),
            ],
            rows: $rows,
            summary: [
                ['label' => 'Reviews', 'value' => (string) count($rows)],
                ['label' => 'Rated A', 'value' => (string) $rated('A')],
                ['label' => 'Rated B', 'value' => (string) $rated('B')],
                ['label' => 'Rated C or D', 'value' => (string) ($rated('C') + $rated('D'))],
            ],
            emptyMessage: 'No performance reviews for these filters.',
            reconciliation: 'Read from staff_performance_records (§2.9). Achievement is the mean of achieved ÷ target across the review\'s metrics; the rating column is the manager\'s recorded judgement, which the achievement rate informs but never overrides. Performance has no bearing on pay — §11 computes commission from branch profit and payroll from base salary, and neither reads a rating.',
        );
    }

    /**
     * The mean of achieved ÷ target across a review's metrics, as a percentage.
     *
     * A target of zero contributes nothing rather than an infinity — an
     * unmeasurable metric should not decide the average.
     */
    private function achievementRate(StaffPerformanceRecord $record): string
    {
        $ratios = [];

        foreach ($record->targets_json as $metric => $target) {
            if ((float) $target <= 0.0) {
                continue;
            }

            $achieved = (float) ($record->achieved_json[$metric] ?? 0);
            $ratios[] = $achieved / (float) $target;
        }

        if ($ratios === []) {
            return '0.000';
        }

        return number_format(array_sum($ratios) / count($ratios) * 100, 3, '.', '');
    }
}

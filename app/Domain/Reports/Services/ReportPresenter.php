<?php

declare(strict_types=1);

namespace App\Domain\Reports\Services;

use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportQuery;
use App\Domain\Reports\DTOs\ReportResult;
use App\Support\Money;

/**
 * Search, sort and pagination over a computed report.
 *
 * ## Why this is presentation rather than query
 *
 * A report is a read-model: it aggregates the operational tables and the ledger
 * into rows that mostly do not correspond one-to-one with database rows at all.
 * A branch P&L row is eleven aggregates across four tables; an age-analysis row
 * is a bucket. There is no `ORDER BY` that could sort those without the report
 * computing them first, so searching and sorting happen where the rows exist.
 *
 * The sets are bounded by construction — branches, staff, buckets, accounts,
 * periods — and the two that are not (loan-level listings) already carry a date
 * window. Nothing here is holding a million rows in memory.
 *
 * ## Totals, and the one thing that has to be got right
 *
 * Totals are the report's own, computed over everything the filters matched.
 * Two rules:
 *
 *   - **Pagination never touches them.** A total that summed only the visible
 *     page would be a different number on page two, which is worse than no
 *     total at all.
 *   - **Search does.** A search narrows what the report is *about*, so the
 *     totals follow it — recomputed by summing the money and number columns of
 *     the surviving rows. Text total cells are dropped rather than carried
 *     over, because "11 payslips" is not true of a filtered subset and a stale
 *     label is a lie a reader has no way to detect.
 */
final class ReportPresenter
{
    /**
     * @return array{result: ReportResult, meta: array<string, mixed>}
     */
    public function present(ReportResult $result, ReportQuery $query): array
    {
        $rows = $result->rows;
        $total = count($rows);

        $searched = false;

        if ($query->hasSearch()) {
            $rows = $this->search($rows, (string) $query->search);
            $searched = true;
        }

        $matched = count($rows);

        /*
         * Held before pagination. Totals are recomputed from these — the rows
         * the search matched, all of them — because a total over the visible
         * page would be a different number on page two.
         */
        $matchedRows = $rows;

        $sort = $this->resolveSort($result->columns, $query->sort);

        if ($sort !== null) {
            $rows = $this->sort($rows, $sort, $query->direction);
        }

        $meta = ['query' => $query->applied()];

        if ($searched) {
            $meta['matchedRows'] = $matched;
            $meta['totalRows'] = $total;
        }

        if ($query->sort !== null && $sort === null) {
            /*
             * Asked to sort by something that is not a column. Reported rather
             * than ignored: the caller believes the order means something, and
             * silently serving an unsorted list is how a reader concludes the
             * data is wrong.
             */
            $meta['sortIgnored'] = $query->sort;
        }

        if ($query->isPaginated()) {
            $perPage = (int) $query->perPage;
            $lastPage = max(1, (int) ceil($matched / $perPage));
            $page = min($query->page, $lastPage);

            $meta['pagination'] = [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $matched,
                'lastPage' => $lastPage,
            ];

            $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);
        }

        return [
            'result' => new ReportResult(
                columns: $result->columns,
                rows: array_values($rows),
                totals: $searched
                    ? $this->recomputeTotals($result, $matchedRows, $matched)
                    : $result->totals,
                summary: $result->summary,
                emptyMessage: $result->emptyMessage,
                reconciliation: $result->reconciliation,
            ),
            'meta' => $meta,
        ];
    }

    /**
     * Rows containing the term in any cell, case-insensitively.
     *
     * Across every column rather than a declared subset: a report's columns are
     * its own, and a search box that silently ignored the one column somebody
     * was looking at would be worse than none. Numbers are searchable too —
     * "450000" finds the loan.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function search(array $rows, string $term): array
    {
        $needle = mb_strtolower(trim($term));

        return array_values(array_filter($rows, static function (array $row) use ($needle): bool {
            foreach ($row as $value) {
                if (is_scalar($value) && str_contains(mb_strtolower((string) $value), $needle)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * The column to sort by, or null if the caller named something that is not
     * one.
     *
     * @param list<ReportColumn> $columns
     */
    private function resolveSort(array $columns, ?string $key): ?ReportColumn
    {
        if ($key === null) {
            return null;
        }

        foreach ($columns as $column) {
            if ($column->key === $key) {
                return $column;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function sort(array $rows, ReportColumn $column, string $direction): array
    {
        $numeric = $column->money || $column->percent || $column->align === 'right';
        $sign = $direction === 'desc' ? -1 : 1;

        usort($rows, static function (array $a, array $b) use ($column, $numeric, $sign): int {
            $left = $a[$column->key] ?? null;
            $right = $b[$column->key] ?? null;

            /*
             * Money is a decimal string and must not be compared as a float:
             * '1000000.10' <=> '999999.99' is correct as a number and wrong as
             * a string, and casting to float is what puts a rounding error into
             * an ordering. bccomp compares the digits.
             */
            if ($numeric) {
                $l = self::numeric($left);
                $r = self::numeric($right);

                return $sign * bccomp($l, $r, 4);
            }

            return $sign * strcasecmp((string) $left, (string) $right);
        });

        return $rows;
    }

    /**
     * A cell as a decimal string bccomp can read.
     *
     * Em dashes, blanks and "—" all sort as zero, which is where a reader
     * expects an absent figure to land.
     */
    private static function numeric(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '0';
        }

        $text = preg_replace('/[^0-9.\-]/', '', (string) $value) ?? '';

        return $text === '' || $text === '-' || $text === '.' ? '0' : $text;
    }

    /**
     * Totals for a searched subset.
     *
     * Only the columns that are actually sums — money, and right-aligned
     * numbers. A text total cell such as "11 payslips" is replaced by the
     * matched count when the report put one in the first column, and dropped
     * otherwise: carrying it unchanged would state a figure about the whole
     * report while every other number on the row describes the subset.
     *
     * @param list<array<string, mixed>> $rows the rows the search matched, before paging
     * @return array<string, mixed>|null
     */
    private function recomputeTotals(ReportResult $result, array $rows, int $matched): ?array
    {
        if ($result->totals === null) {
            return null;
        }

        $totals = [];

        foreach ($result->columns as $column) {
            if (! array_key_exists($column->key, $result->totals)) {
                continue;
            }

            if ($column->money || $column->align === 'right') {
                $totals[$column->key] = Money::sum(array_map(
                    static fn (array $row): Money => Money::of(self::numeric($row[$column->key] ?? '0')),
                    $rows,
                ))->toDecimalString();

                continue;
            }

            // The label cell, which reports use for a count.
            $totals[$column->key] = sprintf('%d matching', $matched);
        }

        return $totals === [] ? null : $totals;
    }
}

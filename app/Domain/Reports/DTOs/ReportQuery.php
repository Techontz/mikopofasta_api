<?php

declare(strict_types=1);

namespace App\Domain\Reports\DTOs;

/**
 * How a computed report is *presented* — searched, sorted, paged.
 *
 * Deliberately separate from `ReportFilters`. Those four — branch, period,
 * from, to — decide **what the figures are**, and §15.6 echoes them back in
 * `filters_applied` so a reader knows what the numbers cover. These decide
 * only which of the resulting rows are shown and in what order, and folding
 * them into `filters_applied` would tell a reader that sorting by branch had
 * changed the totals.
 */
final readonly class ReportQuery
{
    public const int DEFAULT_PER_PAGE = 50;

    public const int MAX_PER_PAGE = 500;

    public function __construct(
        public ?string $search = null,
        public ?string $sort = null,
        public string $direction = 'asc',
        public int $page = 1,
        /**
         * Null means "every row".
         *
         * Pagination is opt-in rather than defaulted, because a report is
         * usually read as a whole: a trial balance or a branch P&L cut off at
         * row fifty is not a shorter report, it is a wrong one. A caller that
         * wants pages asks for them.
         */
        public ?int $perPage = null,
    ) {}

    /** @param array<string, mixed> $query */
    public static function fromArray(array $query): self
    {
        $perPage = isset($query['per_page']) && $query['per_page'] !== ''
            ? max(1, min((int) $query['per_page'], self::MAX_PER_PAGE))
            : null;

        return new self(
            search: self::nullableString($query['search'] ?? null),
            sort: self::nullableString($query['sort'] ?? null),
            direction: strtolower((string) ($query['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
            page: max(1, (int) ($query['page'] ?? 1)),
            perPage: $perPage,
        );
    }

    public function isPaginated(): bool
    {
        return $this->perPage !== null;
    }

    public function hasSearch(): bool
    {
        return $this->search !== null && trim($this->search) !== '';
    }

    /**
     * What `meta.query` echoes — so a caller can see which sort actually
     * applied when the one they asked for was not a column.
     *
     * @return array<string, mixed>
     */
    public function applied(): array
    {
        return array_filter([
            'search' => $this->search,
            'sort' => $this->sort,
            'direction' => $this->sort === null ? null : $this->direction,
            'page' => $this->isPaginated() ? $this->page : null,
            'perPage' => $this->perPage,
        ], static fn (mixed $v): bool => $v !== null);
    }

    private static function nullableString(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }
}

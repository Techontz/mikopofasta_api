<?php

namespace App\Services\Reports\Financial;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Branch + period filter of the financial reports.
 *
 * - `all` for a company-scope employee: every branch account and every HQ (untagged) account;
 * - `all` for a zone/branch-scope employee: only the branches they may see (no HQ);
 * - `hq`: HQ (company-level) accounts only;
 * - a branch id: that branch only.
 */
final readonly class FinancialScope
{
    /**
     * @param  list<int>|null  $branchIds  null = no branch restriction
     */
    public function __construct(
        public int $companyId,
        public ?array $branchIds,
        public bool $includeHq,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $label = 'ALL',
    ) {}

    public function isCompanyWide(): bool
    {
        return $this->branchIds === null && $this->includeHq;
    }

    public function withDates(CarbonImmutable $from, CarbonImmutable $to): self
    {
        return new self($this->companyId, $this->branchIds, $this->includeHq, $from, $to, $this->label);
    }

    /**
     * Whether a (possibly null = HQ) branch id belongs to the scope.
     */
    public function contains(?int $branchId): bool
    {
        if ($branchId === null) {
            return $this->includeHq;
        }

        return $this->branchIds === null || in_array($branchId, $this->branchIds, true);
    }

    /**
     * Restrict a query on a nullable branch column to the scope.
     */
    public function apply(QueryBuilder $query, string $column): QueryBuilder
    {
        if ($this->isCompanyWide()) {
            return $query;
        }

        return $query->where(function (QueryBuilder $inner) use ($column): void {
            if ($this->branchIds === null) {
                $inner->whereNotNull($column);
            } else {
                $inner->whereIn($column, $this->branchIds === [] ? [0] : $this->branchIds);
            }
            if ($this->includeHq) {
                $inner->orWhereNull($column);
            }
        });
    }
}

<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;

/**
 * Data scope of an API report: the company, the branches the signed-in employee may see (narrowed to the
 * branch picked in the live "Select Branch / ALL" filter) and the optional From / To dates.
 * A null branch list means every branch of the company.
 */
final readonly class ReportScope
{
    /**
     * @param  list<int>|null  $branchIds
     */
    public function __construct(
        public int $companyId,
        public ?array $branchIds,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
    ) {}

    public function withDates(?CarbonImmutable $from, ?CarbonImmutable $to): self
    {
        return new self($this->companyId, $this->branchIds, $from, $to);
    }

    public function dated(): bool
    {
        return $this->from !== null || $this->to !== null;
    }

    /**
     * Restrict a query to the company and branch scope (`$table.company_id`, `$table.branch_id`).
     *
     * @template TBuilder of BuilderContract
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function apply(BuilderContract $query, string $table, string $branchColumn = 'branch_id', bool $withCompany = true): BuilderContract
    {
        if ($withCompany) {
            $query->where("{$table}.company_id", $this->companyId);
        }
        if ($this->branchIds !== null) {
            $query->whereIn("{$table}.{$branchColumn}", $this->branchIds === [] ? [0] : $this->branchIds);
        }

        return $query;
    }

    /**
     * Restrict a date column to the From / To range (inclusive); no-op when the bound is missing.
     *
     * @template TBuilder of BuilderContract
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function between(BuilderContract $query, string $column): BuilderContract
    {
        if ($this->from !== null) {
            $query->where($column, '>=', $this->from->toDateString());
        }
        if ($this->to !== null) {
            $query->where($column, '<=', $this->to->toDateString().' 23:59:59');
        }

        return $query;
    }
}

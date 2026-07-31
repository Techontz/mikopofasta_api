<?php

declare(strict_types=1);

namespace App\Domain\Reports\DTOs;

/**
 * A computed report, mirroring the frontend's `ReportResult`.
 *
 * `reconciliation` is not decoration. Phase 8 requires every figure to be
 * traceable, and a number on a screen is only traceable if the reader is told
 * where it came from — so each report states, in its own words, how its
 * figures tie back to the ledger or to the operational tables. An auditor
 * should be able to see the provenance rather than have to trust it.
 */
final readonly class ReportResult
{
    /**
     * @param list<ReportColumn> $columns
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>|null $totals
     * @param list<array{label: string, value: string}> $summary
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public ?array $totals = null,
        public array $summary = [],
        public ?string $emptyMessage = null,
        public ?string $reconciliation = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toMeta(): array
    {
        return array_filter([
            'columns' => array_map(static fn (ReportColumn $c): array => $c->toArray(), $this->columns),
            'totals' => $this->totals,
            'summary' => $this->summary === [] ? null : $this->summary,
            'emptyMessage' => $this->emptyMessage,
            'reconciliation' => $this->reconciliation,
            'rowCount' => count($this->rows),
        ], static fn (mixed $v): bool => $v !== null);
    }
}

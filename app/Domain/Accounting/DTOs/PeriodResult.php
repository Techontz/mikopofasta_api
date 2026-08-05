<?php

declare(strict_types=1);

namespace App\Domain\Accounting\DTOs;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Everything one period earned, broken down far enough to close it.
 *
 * Holds the raw per-account, per-branch movements rather than pre-aggregated
 * totals, because the close needs both views: the sweep posts one line per
 * account, and the reserve is appropriated per branch. Aggregating early would
 * mean querying twice.
 */
final readonly class PeriodResult
{
    /** @param list<AccountMovement> $movements */
    public function __construct(
        public string $period,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public array $movements,
    ) {}

    /** @return list<AccountMovement> */
    public function forBranch(?int $branchId): array
    {
        return array_values(array_filter(
            $this->movements,
            static fn (AccountMovement $m): bool => $m->branchId === $branchId,
        ));
    }

    /**
     * Every branch id that moved, plus null if any unbranched line did.
     *
     * Null is a real bucket, not an oversight: a capital injection or an
     * HQ-level expense carries no branch, and its profit still has to be
     * recognised somewhere or the close would not balance.
     *
     * @return list<int|null>
     */
    public function branchIds(): array
    {
        $ids = array_map(static fn (AccountMovement $m): ?int => $m->branchId, $this->movements);

        $unique = [];

        foreach ($ids as $id) {
            if (! in_array($id, $unique, true)) {
                $unique[] = $id;
            }
        }

        return $unique;
    }

    public function incomeTotal(?int $branchId = null, bool $allBranches = true): Money
    {
        return $this->total(true, $branchId, $allBranches);
    }

    public function expenseTotal(?int $branchId = null, bool $allBranches = true): Money
    {
        return $this->total(false, $branchId, $allBranches);
    }

    /** Income minus expense. Signed: a period in loss returns a negative. */
    public function profit(?int $branchId = null, bool $allBranches = true): Money
    {
        return $this->incomeTotal($branchId, $allBranches)
            ->subtract($this->expenseTotal($branchId, $allBranches));
    }

    public function isEmpty(): bool
    {
        return $this->movements === [];
    }

    private function total(bool $income, ?int $branchId, bool $allBranches): Money
    {
        $selected = $allBranches ? $this->movements : $this->forBranch($branchId);

        return Money::sum(array_map(
            static fn (AccountMovement $m): Money => $m->net,
            array_values(array_filter(
                $selected,
                static fn (AccountMovement $m): bool => $m->isIncome() === $income,
            )),
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Hr\DTOs;

use App\Support\Money;
use App\Support\Percentage;

/**
 * One branch's commission pool for one period, computed and not yet stored.
 *
 * Mirrors the frontend's `PoolComputation`. `distributable` is carried
 * separately from `poolAmount` on purpose: a branch that has not cleared its
 * loss has a pool of zero *and* is blocked, and a caller that only looked at
 * the amount could not tell "nothing to share" from "not allowed to share".
 */
final readonly class PoolComputation
{
    public function __construct(
        public Money $branchProfit,
        /**
         * What the month-end close took for the Reserve fund before anything
         * else — Decision Register D1. Zero for a period that was never closed,
         * and for a branch whose profit was negative.
         */
        public Money $reserveAppropriation,
        public Money $lossCarryForward,
        public Money $hqHoldAmount,
        public Money $distributableProfit,
        public Percentage $poolPercentage,
        public Money $poolAmount,
        public bool $distributable,
    ) {}

    /**
     * The `commission_pools` row for this computation.
     *
     * Every deduction is stored, in the order it was applied, so a manager
     * asking why a pool is what it is can be shown the whole subtraction rather
     * than its result.
     *
     * @return array{
     *     branch_profit: string,
     *     reserve_appropriation: string,
     *     loss_carry_forward: string,
     *     hq_hold_amount: string,
     *     distributable_profit: string,
     *     pool_percentage: string,
     *     pool_amount: string
     * }
     */
    public function toPoolRow(): array
    {
        return [
            'branch_profit' => $this->branchProfit->toDecimalString(),
            'reserve_appropriation' => $this->reserveAppropriation->toDecimalString(),
            'loss_carry_forward' => $this->lossCarryForward->toDecimalString(),
            'hq_hold_amount' => $this->hqHoldAmount->toDecimalString(),
            'distributable_profit' => $this->distributableProfit->toDecimalString(),
            'pool_percentage' => $this->poolPercentage->toDecimalString(),
            'pool_amount' => $this->poolAmount->toDecimalString(),
        ];
    }
}

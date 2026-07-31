<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use App\Domain\Hr\DTOs\PoolComputation;
use App\Models\StaffProfile;
use App\Support\Money;
use App\Support\Percentage;
use Illuminate\Support\Collection;

/**
 * THE commission engine — spec §11, mirroring the frontend's
 * `computeCommissionPool` and `distributePool`.
 *
 * Commission here is **branch-performance-based, never individual-sales-based**
 * (§11, and the frontend states it in the same words). Nobody earns commission
 * for closing a loan; a branch earns a pool for being profitable, and the pool
 * is shared among its eligible staff. That is a deliberate incentive design —
 * it rewards a branch's book quality rather than its origination volume — and
 * it is why this calculator never sees a loan.
 *
 * Pure, like PayrollCalculator: it computes and returns. The pool is not a
 * ledger event, and §11 is clear that commission reaches the books only as an
 * expense on the recipient's payroll entry — posting a pool-level entry too
 * would recognise the same money twice.
 */
final class CommissionCalculator
{
    /** HQ's cut of branch profit, taken before anything is distributable. */
    public const string HQ_HOLD_RATE = '2.000';

    /** The share of distributable profit that becomes the staff pool. */
    public const string POOL_RATE = '20.000';

    /** A zone manager's override on the pools of the branches they oversee. */
    public const string ZONE_OVERRIDE_RATE = '5.000';

    /**
     * The pool a branch has earned.
     *
     * The order is §11's and it matters: HQ takes its 2% of gross profit
     * first, then the carried-forward loss is offset, and only what survives
     * both is distributable. Taking the hold after the loss would let a
     * loss-making branch shrink HQ's cut.
     */
    public function computePool(Money $branchProfit, Money $lossCarryForward): PoolComputation
    {
        $hqHold = $branchProfit->percentage(Percentage::of(self::HQ_HOLD_RATE));

        $distributableProfit = $branchProfit->subtract($lossCarryForward)->subtract($hqHold);

        // §11's hard rule, stated as a boolean rather than left to each caller
        // to re-derive: "commission_distributions for a branch/period cannot
        // be created while distributable_profit <= 0 (loss must be offset
        // first)."
        $distributable = $distributableProfit->isPositive();

        return new PoolComputation(
            branchProfit: $branchProfit,
            lossCarryForward: $lossCarryForward,
            hqHoldAmount: $hqHold,
            distributableProfit: $distributableProfit,
            poolPercentage: Percentage::of(self::POOL_RATE),

            // A loss-making branch produces a pool of exactly zero — never a
            // negative one, which would read as staff owing the company.
            poolAmount: $distributable
                ? $distributableProfit->percentage(Percentage::of(self::POOL_RATE))
                : Money::zero(),

            distributable: $distributable,
        );
    }

    /**
     * Splits a pool among eligible staff, weighted by base-salary share (§11).
     *
     * Seniority is the weighting the specification chose, and it follows from
     * commission being a branch reward rather than a personal one: there is no
     * individual sales figure to weight by, so the proxy is the size of the
     * role.
     *
     * Each share is `pool × (salary ÷ total)` rounded half-up independently,
     * exactly as the frontend computes it. The shares can therefore differ
     * from the pool by a cent or two, which is harmless here — a pool is a
     * computed entitlement, not cash sitting in an account waiting to be
     * emptied, and each share is expensed on its own balanced entry. Using
     * `Money::allocate()` would force them to sum exactly but would no longer
     * match the contract.
     *
     * @param Collection<int, StaffProfile> $eligible
     * @return list<array{staffProfileId: int, shareAmount: Money}>
     */
    public function distributePool(Money $poolAmount, Collection $eligible): array
    {
        $totalBase = Money::sum($eligible->map(fn (StaffProfile $s): Money => $s->baseSalary()));

        if ($totalBase->isZero() || ! $poolAmount->isPositive()) {
            return [];
        }

        $shares = [];

        foreach ($eligible as $staff) {
            $shares[] = [
                'staffProfileId' => (int) $staff->getKey(),
                'shareAmount' => $poolAmount->proportion($staff->baseSalary(), $totalBase),
            ];
        }

        return $shares;
    }

    /**
     * A zone manager's override: a percentage of the combined pools of the
     * branches in their zone (§11's "% of the branch pools they oversee").
     */
    public function zoneOverride(Money $totalPoolBase): Money
    {
        return $totalPoolBase->percentage(Percentage::of(self::ZONE_OVERRIDE_RATE));
    }

    public function zoneOverridePercentage(): Percentage
    {
        return Percentage::of(self::ZONE_OVERRIDE_RATE);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Loans\Services;

use App\Domain\Loans\Enums\PenaltyType;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Applies a product's penalty configuration to one overdue installment —
 * spec §7, mirroring the frontend's `computePenalty`.
 *
 * The single implementation: the overdue job (Phase 6) and any report that
 * projects a penalty both call this rather than re-deriving it.
 *
 * On `penalty_rate`'s dual meaning — a percentage for the two percentage
 * types, a flat TZS amount for `flat_fee` (§2.3, and see OSC-2 on the
 * migration) — the two readings are separated here so no caller has to
 * remember which it is holding.
 */
final class PenaltyCalculator
{
    public function daysPastDue(LoanSchedule $schedule, CarbonImmutable $asOf): int
    {
        $due = $schedule->due_date->startOfDay();
        $today = $asOf->startOfDay();

        return max(0, (int) $due->diffInDays($today, false));
    }

    /**
     * The penalty owed on this installment as of `$asOf`, or zero.
     */
    public function compute(LoanSchedule $schedule, LoanProduct $product, CarbonImmutable $asOf): Money
    {
        $daysPastDue = $this->daysPastDue($schedule, $asOf);

        // Within the grace period nothing is owed at all (§2.3).
        if ($daysPastDue <= $product->penalty_grace_days) {
            return Money::zero();
        }

        $outstanding = $schedule->outstandingTotal();

        if (! $outstanding->isPositive()) {
            return Money::zero();
        }

        $penalty = match ($product->penalty_type) {
            // penalty_rate is an AMOUNT here, not a rate.
            PenaltyType::FlatFee => $product->penaltyFlatAmount(),

            // Charged for each day beyond the grace period, not for every day
            // since the due date — the grace period would otherwise be
            // retroactively cancelled the moment it lapsed.
            PenaltyType::PercentagePerDay => $outstanding->percentage(
                $product->penaltyRate()->times($daysPastDue - $product->penalty_grace_days),
            ),

            PenaltyType::PercentageOfOverdue => $outstanding->percentage($product->penaltyRate()),
        };

        $cap = $product->penaltyCapAmount();

        return $cap === null ? $penalty : $penalty->min($cap);
    }
}

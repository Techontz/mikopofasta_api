<?php

declare(strict_types=1);

namespace App\Domain\Loans\Services;

use App\Domain\Loans\DTOs\TopupEligibility;
use App\Domain\Loans\Enums\LoanScheduleStatus;
use App\Domain\Loans\Enums\LoanStatus;
use App\Models\Loan;
use App\Support\Money;
use App\Support\Percentage;

/**
 * Top-up eligibility — a pure read-model check (§6, §15.2), mirroring the
 * frontend's `checkTopupEligibility`.
 *
 * §6 describes it as "a pure read-model check (paid % >= threshold AND no
 * arrears) exposed as a dedicated endpoint the frontend calls before even
 * showing a 'Top Up' button". Nothing here writes.
 *
 * The frontend has no top-up screen yet (readiness report gap 2), so this
 * backs `GET /loans/{id}/topup-eligibility` and nothing else. The top-up
 * itself — which would create a second loan and settle the first — is not
 * implemented, because how the outstanding balance rolls into the new
 * principal is not specified anywhere.
 */
final class TopupEligibilityChecker
{
    /**
     * The share of the loan that must already be repaid. The frontend's own
     * default; §6 says "paid % >= threshold" without naming a number.
     */
    public const int DEFAULT_MINIMUM_PAID_PERCENT = 60;

    public function check(Loan $loan, ?int $minimumPaidPercent = null): TopupEligibility
    {
        $minimum = $minimumPaidPercent ?? self::DEFAULT_MINIMUM_PAID_PERCENT;
        $reasons = [];

        $schedules = $loan->schedules;

        $totalDue = Money::sum($schedules->map(fn ($s) => $s->totalDue()));
        $totalPaid = Money::sum($schedules->map(fn ($s) => $s->totalPaid()));

        $paidPercent = $totalDue->isZero()
            ? Percentage::zero()
            : Percentage::ofThousandths((int) round(
                $totalPaid->minor * 100 * Percentage::SCALE / $totalDue->minor,
            ));

        if ($loan->status !== LoanStatus::Active) {
            $reasons[] = 'Only an active loan can be topped up.';
        }

        if ($paidPercent->thousandthsOfPercent < $minimum * Percentage::SCALE) {
            $reasons[] = sprintf(
                'Only %s%% repaid — %d%% is required.',
                $paidPercent->toDecimalString(),
                $minimum,
            );
        }

        $hasArrears = $schedules->contains(
            fn ($s): bool => $s->status === LoanScheduleStatus::Overdue && $s->outstandingTotal()->isPositive(),
        );

        if ($hasArrears) {
            $reasons[] = 'Loan has overdue installments.';
        }

        return new TopupEligibility(
            eligible: $reasons === [],
            paidPercent: $paidPercent,
            reasons: $reasons,
        );
    }
}

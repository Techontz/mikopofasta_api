<?php

namespace App\Services;

/**
 * Interest formulas.
 *
 * SIMPLE was verified against live data: the rate is applied once to the principal
 * (100,000 @ 30% over 1 week → 130,000; 20,000 @ 30% over 3 months → 26,000) and the
 * per-session "Restoration" is (principal + interest + insurance) / sessions
 * (600,000 @ 20% + 100,000 insurance over 5 weeks → 164,000).
 *
 * FLAT RATE and REDUCING could not be observed on the live system; they follow the
 * conventional definitions (rate per repayment period, and declining balance).
 */
class LoanCalculator
{
    /**
     * @return array{interest: float, total: float, restoration: float}
     */
    public function calculate(string $formula, float $principal, float $ratePercent, int $sessions, float $insurance = 0): array
    {
        $sessions = max(1, $sessions);
        $rate = $ratePercent / 100;

        $interest = match (strtoupper($formula)) {
            'FLATRATE', 'FLAT RATE' => $principal * $rate * $sessions,
            'REDUCING' => $this->reducingInterest($principal, $rate, $sessions),
            default => $principal * $rate,
        };

        $interest = round($interest, 2);
        $total = round($principal + $interest, 2);

        return [
            'interest' => $interest,
            'total' => $total,
            'restoration' => round(($total + $insurance) / $sessions, 2),
        ];
    }

    private function reducingInterest(float $principal, float $rate, int $sessions): float
    {
        if ($rate <= 0) {
            return 0;
        }

        $payment = $principal * $rate / (1 - (1 + $rate) ** -$sessions);

        return $payment * $sessions - $principal;
    }
}

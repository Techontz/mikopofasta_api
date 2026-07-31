<?php

declare(strict_types=1);

namespace App\Domain\Loans\DTOs;

use App\Support\Percentage;

/**
 * The answer to `GET /loans/{id}/topup-eligibility` — mirrors the frontend's
 * TopupEligibility.
 */
final readonly class TopupEligibility
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public bool $eligible,
        public Percentage $paidPercent,
        public array $reasons,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible,
            'paidPercent' => $this->paidPercent->toDecimalString(),
            'reasons' => $this->reasons,
        ];
    }
}

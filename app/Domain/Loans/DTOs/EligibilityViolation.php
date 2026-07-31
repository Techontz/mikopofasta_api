<?php

declare(strict_types=1);

namespace App\Domain\Loans\DTOs;

/**
 * One failed eligibility gate. Mirrors the frontend's EligibilityViolation:
 * a stable machine-readable code plus the human sentence the UI renders.
 */
final readonly class EligibilityViolation
{
    public function __construct(
        public string $code,
        public string $message,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'message' => $this->message];
    }
}

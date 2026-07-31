<?php

declare(strict_types=1);

namespace App\Domain\Admin\DTOs;

/** Input for a repayment schedule — Settings → Repayment Schedules. */
final readonly class RepaymentScheduleData
{
    public function __construct(
        public string $name,
        public string $code,
        public int $frequencyDays,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        return new self(
            name: trim((string) $validated['name']),
            // Upper-cased: the seeded set is DAILY/WEEKLY/MONTHLY/GROUP, and a
            // code differing only in case would pass the unique index while
            // reading as the same thing to everyone looking at it.
            code: mb_strtoupper(trim((string) $validated['code'])),
            frequencyDays: (int) $validated['frequencyDays'],
        );
    }
}

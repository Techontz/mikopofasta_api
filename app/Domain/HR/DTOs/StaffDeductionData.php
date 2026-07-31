<?php

declare(strict_types=1);

namespace App\Domain\Hr\DTOs;

use App\Domain\Hr\Enums\DeductionType;
use App\Support\Money;

/** Input for a penalty deduction — HRM → Staff → Deductions. */
final readonly class StaffDeductionData
{
    public function __construct(
        public DeductionType $type,
        public Money $amount,
        public string $period,
        public string $reason,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        return new self(
            /*
             * Only a penalty can be recorded by hand. Staff fund, loan and
             * advance deductions are derived from a rate or a balance, and a
             * hand-entered one would sit alongside the computed one and be
             * deducted twice. Enforced in validation as well; kept here so the
             * DTO cannot express the invalid state either.
             */
            type: DeductionType::Penalty,
            amount: Money::of((string) $validated['amount']),
            period: trim((string) $validated['period']),
            reason: trim((string) $validated['reason']),
        );
    }
}

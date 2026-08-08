<?php

declare(strict_types=1);

namespace App\Domain\Hr\DTOs;

use App\Domain\Hr\Enums\AllowanceType;
use App\Support\Money;

/** Input for an allowance grant — HRM → Staff → Allowances. */
final readonly class StaffAllowanceData
{
    public function __construct(
        public AllowanceType $type,
        public Money $amount,
        /** NULL means recurring; a period means that month alone. */
        public ?string $period,
        public ?string $reason,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        $type = AllowanceType::from((string) $validated['type']);
        $period = isset($validated['period']) ? trim((string) $validated['period']) : '';

        return new self(
            type: $type,
            amount: Money::of((string) $validated['amount']),

            /*
             * A bonus is always one-off, whatever the caller sent.
             *
             * Not a silent correction of a mistake — it is the rule §10 implies
             * and this is the one place it can be applied without every caller
             * remembering it. A recurring bonus is a salary increase, and a
             * salary increase belongs on the employee's profile where it is
             * visible, not hidden in an allowance row.
             */
            period: $type === AllowanceType::Bonus
                ? ($period === '' ? now()->format('Y-m') : $period)
                : ($period === '' ? null : $period),

            reason: isset($validated['reason']) && trim((string) $validated['reason']) !== ''
                ? trim((string) $validated['reason'])
                : null,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Repayments\DTOs;

use App\Support\Money;

/**
 * How much of a payment landed on one installment, split by component.
 * Becomes a `payment_allocations` row (§2.6).
 */
final readonly class AllocationLine
{
    public function __construct(
        public int $scheduleId,
        public Money $penalty,
        public Money $interest,
        public Money $principal,
    ) {}

    public function total(): Money
    {
        return $this->penalty->add($this->interest)->add($this->principal);
    }

    /**
     * The `payment_allocations` row for this line (§2.6).
     *
     * The shape is declared precisely rather than as array<string, mixed> so
     * static analysis can check it against the model's own properties — a
     * renamed column becomes an error here instead of a silently dropped
     * amount.
     *
     * @return array{
     *     payment_id: int,
     *     loan_schedule_id: int,
     *     penalty_allocated: string,
     *     interest_allocated: string,
     *     principal_allocated: string
     * }
     */
    public function toDatabaseRow(int $paymentId): array
    {
        return [
            'payment_id' => $paymentId,
            'loan_schedule_id' => $this->scheduleId,
            'penalty_allocated' => $this->penalty->toDecimalString(),
            'interest_allocated' => $this->interest->toDecimalString(),
            'principal_allocated' => $this->principal->toDecimalString(),
        ];
    }
}

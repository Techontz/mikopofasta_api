<?php

declare(strict_types=1);

namespace App\Domain\Loans\DTOs;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * One row of a generated repayment plan, before it is persisted.
 *
 * Carries only what the generator computes — the paid columns and status are
 * the repayment engine's business (Phase 6), not origination's.
 */
final readonly class ScheduleInstallment
{
    public function __construct(
        public int $installmentNumber,
        public CarbonImmutable $dueDate,
        public Money $principalDue,
        public Money $interestDue,
    ) {}

    public function total(): Money
    {
        return $this->principalDue->add($this->interestDue);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabaseRow(int $loanId): array
    {
        return [
            'loan_id' => $loanId,
            'installment_number' => $this->installmentNumber,
            'due_date' => $this->dueDate->toDateString(),
            'principal_due' => $this->principalDue->toDecimalString(),
            'interest_due' => $this->interestDue->toDecimalString(),
        ];
    }
}

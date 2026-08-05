<?php

declare(strict_types=1);

namespace App\Domain\Repayments\DTOs;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * What one pass of ApplyDueAdvancesAction did.
 *
 * A value object rather than a table: unlike a penalty run, this job creates
 * nothing that needs re-reading later. Every movement it makes is already
 * recorded twice over — once in `loan_advances` as a consumption carrying its
 * journal entry, once in the audit log. A summary row would be a third copy
 * that could disagree with both.
 */
final readonly class AdvanceConsumptionRun
{
    public function __construct(
        public CarbonImmutable $runDate,
        public int $loansSettled,
        public int $installmentsSettled,
        public Money $advanceConsumed,
    ) {}

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        return [
            'run_date' => $this->runDate->toDateString(),
            'loans_settled' => $this->loansSettled,
            'installments_settled' => $this->installmentsSettled,
            'advance_consumed' => $this->advanceConsumed->toDecimalString(),
        ];
    }
}

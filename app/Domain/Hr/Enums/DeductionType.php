<?php

declare(strict_types=1);

namespace App\Domain\Hr\Enums;

use App\Domain\Ledger\Enums\SystemAccountCode;

/** Mirrors the frontend's DEDUCTION_TYPES and `deductions.type` (§2.9). */
enum DeductionType: string
{
    case StaffFund = 'staff_fund';
    case Loan = 'loan';
    case Advance = 'advance';
    case Penalty = 'penalty';

    /**
     * The account a deduction is credited to when payroll is finalized.
     *
     * A deduction does not vanish — it moves from what the employee is owed
     * into the fund it contributes to or the receivable it repays, which is
     * why each type resolves to a real account rather than being netted off
     * the salary silently.
     */
    public function creditAccount(): SystemAccountCode
    {
        return match ($this) {
            self::StaffFund => SystemAccountCode::StaffFund,
            self::Loan => SystemAccountCode::StaffLoanReceivable,
            self::Advance => SystemAccountCode::StaffAdvanceReceivable,

            // A payroll penalty is an amount withheld rather than recovered
            // against anything, so it lands with the other withheld money.
            self::Penalty => SystemAccountCode::StaffFund,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

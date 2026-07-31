<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Enums;

/**
 * What caused an entry — mirrors the frontend's JOURNAL_SOURCE_TYPES and
 * `journal_entries.source_type` (§2.7).
 *
 * Together with `source_id` this is how a ledger entry is traced back to the
 * payment, loan or payroll run that produced it.
 */
enum JournalSourceType: string
{
    case CapitalInjection = 'capital_injection';
    case LoanDisbursement = 'loan_disbursement';
    case Repayment = 'repayment';
    case SuspenseResolution = 'suspense_resolution';
    case Expense = 'expense';
    case MonthEndProfit = 'month_end_profit';
    case Dividend = 'dividend';
    case Payroll = 'payroll';
    case Commission = 'commission';
    case StaffLoan = 'staff_loan';
    case StaffAdvance = 'staff_advance';
    /*
     * Money moved between the company's own accounts — branch float, and
     * account-to-account transfers (Capital module). Not income, not expense:
     * both sides are assets, so it nets to nothing on the P&L.
     */
    case Transfer = 'transfer';

    case Reversal = 'reversal';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

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
    /*
     * A held Customer Advance being spent on an installment that has reached
     * its due date — the second half of the client's prepaid-credit ruling.
     *
     * Distinct from `repayment` on purpose. No cash moves: the money was
     * received, banked and recognised as a liability when it arrived, and this
     * entry only converts that liability into settled debt. Filing it as a
     * repayment would make the day's cash receipts read higher than the day's
     * cash, which is exactly the sort of thing a treasury reconciliation is
     * supposed to catch.
     */
    case AdvanceConsumption = 'advance_consumption';
    case Expense = 'expense';
    case MonthEndProfit = 'month_end_profit';

    /*
     * Reserve, both directions — Decision Register D1.
     *
     * Appropriation is what the month-end close does with realised profit;
     * utilisation is what Admin approves spending it on. Two cases rather than
     * one, because a reserve that only ever grew and a reserve that had been
     * drawn down would otherwise be indistinguishable in the ledger, and the
     * whole point of D1's approval requirement is that drawdowns are visible.
     */
    case ReserveAppropriation = 'reserve_appropriation';
    case ReserveUtilisation = 'reserve_utilisation';

    /*
     * §5's two ends of a bad debt. Kept apart from `repayment` because a
     * recovery is not a repayment — the loan it settles was already written off
     * the book, so the credit goes to Recovered Loans and not to receivable.
     */
    case WriteOff = 'write_off';
    case Recovery = 'recovery';

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

    /**
     * Whether this entry is part of closing a period rather than trading in it.
     *
     * The month-end close sweeps income and expense into Profit, so from the
     * moment a period is closed its income accounts read zero. That is correct
     * for a balance sheet — the earnings really have moved to equity — and
     * wrong for a profit and loss statement, which is asking what the period
     * earned, not what is left over afterwards.
     *
     * So P&L-shaped reads exclude these, and balance-sheet-shaped reads do not.
     * Stated once here rather than as a literal list in each caller, because a
     * report that forgot one would silently report a closed period as having
     * earned nothing at all.
     *
     * @return list<self>
     */
    public static function periodClosing(): array
    {
        return [self::MonthEndProfit, self::ReserveAppropriation];
    }

    /** @return list<string> */
    public static function periodClosingValues(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::periodClosing());
    }

    public function isPeriodClosing(): bool
    {
        return in_array($this, self::periodClosing(), true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

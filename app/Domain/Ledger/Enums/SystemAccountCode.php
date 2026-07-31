<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Enums;

/**
 * The fixed system accounts — spec §5, mirroring the frontend's
 * SYSTEM_ACCOUNT_CODES.
 *
 * These are the accounts the posting engine references by name rather than by
 * id, so no posting path ever hardcodes a magic string. `is_system = true` on
 * every one; dynamic accounts (bank 8xxx, branch teller cash, expense 6xxx)
 * are created from their owning records instead.
 */
enum SystemAccountCode: string
{
    case Capital = '1000';
    case Principal = '1100';
    case LoanReceivable = '1200';
    case OutstandingLoan = '1300';
    case OutstandingInterest = '1400';
    case InterestIncome = '2000';
    case FeeIncome = '2100';
    case PenaltyIncome = '2200';
    case Reserve = '3000';
    case Profit = '3100';
    case LoanArrears = '4000';
    case DefaultLoan = '4100';
    case WriteOff = '4200';
    case RecoveredLoans = '4300';
    case Suspense = '5000';
    case StaffFund = '7000';
    case Dividend = '7100';
    case Offset = '7200';

    /*
     * The five payroll accounts.
     *
     * §5's table lists eighteen accounts, but §5's own canonical postings name
     * three more that the table omits — "Payroll: Dr Salary Expense · Cr Staff
     * Payable" and "Commission: Dr Commission Expense · Cr Staff Payable" —
     * and §11 adds staff loan and advance receivables. The frontend resolved
     * the gap by defining all five as system accounts with fixed codes, and
     * those codes are reproduced here exactly. Nothing is invented: the
     * postings that need them were always in the specification.
     */
    case SalaryExpense = '6000';
    case CommissionExpense = '6100';
    case StaffLoanReceivable = '7010';
    case StaffAdvanceReceivable = '7020';
    case StaffPayable = '7050';

    public function accountName(): string
    {
        return match ($this) {
            self::Capital => 'Capital Account',
            self::Principal => 'Principal Account',
            self::LoanReceivable => 'Loan Receivable Account',
            self::OutstandingLoan => 'Outstanding Loan Account',
            self::OutstandingInterest => 'Outstanding Interest Account',
            self::InterestIncome => 'Interest Income Account',
            self::FeeIncome => 'Fee Income Account',
            self::PenaltyIncome => 'Penalty Income Account',
            self::Reserve => 'Reserve Account',
            self::Profit => 'Profit Account',
            self::LoanArrears => 'Loan Arrears Account',
            self::DefaultLoan => 'Default Loan Account',
            self::WriteOff => 'Write-Off Account',
            self::RecoveredLoans => 'Recovered Loans Account',
            self::Suspense => 'Suspense Account',
            self::StaffFund => 'Staff Fund Account',
            self::Dividend => 'Dividend Account',
            self::Offset => 'Offset Account',
            self::SalaryExpense => 'Salary Expense',
            self::CommissionExpense => 'Commission Expense',
            self::StaffLoanReceivable => 'Staff Loan Receivable',
            self::StaffAdvanceReceivable => 'Staff Advance Receivable',
            self::StaffPayable => 'Staff Payable',
        };
    }

    /**
     * The account's type.
     *
     * Principal is EQUITY, not asset — the frontend's chart of accounts
     * explains why: per the business docs it is only ever credited at
     * disbursement and never debited on repayment, making it a running measure
     * of capital deployed into the loan book rather than a balance-sheet asset.
     */
    public function type(): AccountType
    {
        return match ($this) {
            self::Capital, self::Principal, self::Profit, self::Dividend => AccountType::Equity,
            self::LoanReceivable, self::OutstandingLoan, self::OutstandingInterest, self::DefaultLoan,
            self::StaffLoanReceivable, self::StaffAdvanceReceivable => AccountType::Asset,
            self::InterestIncome, self::FeeIncome, self::PenaltyIncome, self::RecoveredLoans => AccountType::Income,
            self::WriteOff, self::SalaryExpense, self::CommissionExpense => AccountType::Expense,
            self::StaffFund, self::StaffPayable => AccountType::Liability,
            self::Reserve, self::LoanArrears, self::Suspense, self::Offset => AccountType::Control,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

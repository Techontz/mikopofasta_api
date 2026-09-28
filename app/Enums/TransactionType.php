<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Business event that caused a journal entry (Cashflow & Fund Flow Specification §28, §32).
 *
 * One journal may carry several components (a loan repayment posts principal, penalty, interest, reserve and
 * insurance lines); the type names the event, the lines carry the components.
 */
enum TransactionType: string
{
    case CapitalContribution = 'capital_contribution';
    case AssetCapitalContribution = 'asset_capital_contribution';
    case OpeningBalance = 'opening_balance';
    case InternalTransfer = 'internal_transfer';

    case LoanDisbursement = 'loan_disbursement';
    case LoanRepayment = 'loan_repayment';
    case LoanPenaltyPayment = 'loan_penalty_payment';
    case PenaltyAccrual = 'penalty_accrual';
    case PenaltyWaiver = 'penalty_waiver';
    case LoanWriteOff = 'loan_write_off';
    case LoanRecovery = 'loan_recovery';

    case TellerCashReceipt = 'teller_cash_receipt';
    case TellerCashBanked = 'teller_cash_banked';
    case SuspenseReceipt = 'suspense_receipt';
    case SuspenseAllocation = 'suspense_allocation';
    case SuspenseRefund = 'suspense_refund';

    case SalaryAdvanceDisbursement = 'salary_advance_disbursement';
    case SalaryAdvanceRepayment = 'salary_advance_repayment';

    case StaffLoanDisbursement = 'staff_loan_disbursement';
    case StaffLoanRepayment = 'staff_loan_repayment';
    case StaffSalaryAdvanceDisbursement = 'staff_salary_advance_disbursement';
    case StaffFundWithdrawal = 'staff_fund_withdrawal';

    case Expense = 'expense';
    case PayrollRecognition = 'payroll_recognition';
    case PayrollPayment = 'payroll_payment';

    case MonthEndClosing = 'month_end_closing';
    case HqProfitHold = 'hq_profit_hold';
    case CommissionAllocation = 'commission_allocation';
    case CommissionPayment = 'commission_payment';
    case ProfitReinvestment = 'profit_reinvestment';
    case DividendDeclaration = 'dividend_declaration';
    case DividendPayment = 'dividend_payment';

    case SavingsDeposit = 'savings_deposit';
    case SavingsWithdrawal = 'savings_withdrawal';
    case AgentCollection = 'agent_collection';

    case Manual = 'manual';
    case Reversal = 'reversal';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::HqProfitHold => 'HQ Profit Hold',
            self::StaffSalaryAdvanceDisbursement => 'Staff Salary Advance',
            default => Str::headline($this->name),
        };
    }

    /**
     * Period-closing entries: they finalise a closed period and can never be reversed (reopening is not supported).
     */
    public function isPeriodClosing(): bool
    {
        return in_array($this, [self::MonthEndClosing, self::HqProfitHold], true);
    }

    /**
     * Central inference used when a caller does not pass a type and by the backfill of existing entries.
     * Deterministic: source model class, description prefix and the accounts debited/credited. Unknown → null.
     *
     * @param  list<string>  $debitAccountKeys  account keys ({@see Account} values) carrying a debit
     * @param  list<string>  $creditAccountKeys  account keys carrying a credit
     */
    public static function infer(?string $sourceType, string $description, array $debitAccountKeys = [], array $creditAccountKeys = [], bool $isReversal = false): ?self
    {
        if ($isReversal || str_starts_with($description, 'REVERSAL:')) {
            return self::Reversal;
        }

        $text = strtoupper(trim($description));

        if ($sourceType === null || $sourceType === '') {
            return str_starts_with($text, 'OPENING BALANCE') ? self::OpeningBalance : self::Manual;
        }

        $fixedAssets = array_map(fn (Account $account): string => $account->value, Account::fixedAssets());

        return match (class_basename($sourceType)) {
            'Capital' => array_intersect($debitAccountKeys, $fixedAssets) !== [] ? self::AssetCapitalContribution : self::CapitalContribution,
            'BankAccount' => self::OpeningBalance,
            'FloatTransfer', 'BankTransfer', 'HqTransaction' => self::InternalTransfer,
            'Loan' => match (true) {
                str_starts_with($text, 'LOAN DISBURSEMENT') => self::LoanDisbursement,
                str_starts_with($text, 'WRITE-OFF') => self::LoanWriteOff,
                str_starts_with($text, 'RECOVERY') => self::LoanRecovery,
                default => null,
            },
            'LoanTransaction' => self::LoanRepayment,
            'Penalty' => match (true) {
                str_starts_with($text, 'PENALTY ACCRUAL') => self::PenaltyAccrual,
                str_starts_with($text, 'PENALTY WAIVER') => self::PenaltyWaiver,
                default => self::LoanPenaltyPayment,
            },
            'PenaltyPayment' => self::LoanPenaltyPayment,
            'WriteOff' => self::LoanWriteOff,
            'LoanRecovery' => self::LoanRecovery,
            'Payment' => match (true) {
                str_starts_with($text, 'TELLER CASH') => self::TellerCashReceipt,
                str_starts_with($text, 'SUSPENSE ALLOCATION') => self::SuspenseAllocation,
                str_starts_with($text, 'SUSPENSE REFUND') => self::SuspenseRefund,
                str_starts_with($text, 'SUSPENSE') => self::SuspenseReceipt,
                default => null,
            },
            'TellerDeposit' => self::TellerCashBanked,
            'SalaryAdvance' => self::SalaryAdvanceDisbursement,
            'SalaryAdvancePayment' => self::SalaryAdvanceRepayment,
            'StaffLoan' => self::StaffLoanDisbursement,
            'StaffLoanPayment' => self::StaffLoanRepayment,
            'StaffSalaryAdvance' => self::StaffSalaryAdvanceDisbursement,
            'StaffFundWithdrawal' => self::StaffFundWithdrawal,
            'ExpenseRequest' => self::Expense,
            'PayrollRun' => self::PayrollRecognition,
            'SalaryPayment' => self::PayrollPayment,
            'AccountingPeriod' => match (true) {
                str_starts_with($text, 'MONTH END CLOSING') => self::MonthEndClosing,
                str_starts_with($text, 'HQ 2% HOLD') => self::HqProfitHold,
                default => null,
            },
            'CommissionAllocation' => str_starts_with($text, 'COMMISSION PAYMENT') ? self::CommissionPayment : self::CommissionAllocation,
            'BranchPeriodResult' => self::CommissionAllocation,
            'DividendDeclaration' => str_starts_with($text, 'PROFIT REINVESTMENT') ? self::ProfitReinvestment : self::DividendDeclaration,
            'DividendPayment' => self::DividendPayment,
            'Saving' => match (true) {
                in_array(Account::HqSaving->value, $debitAccountKeys, true) => self::SavingsDeposit,
                in_array(Account::HqSaving->value, $creditAccountKeys, true) => self::SavingsWithdrawal,
                default => null,
            },
            'AgentTransaction' => self::AgentCollection,
            default => null,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type): array => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}

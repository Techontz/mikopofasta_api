<?php

namespace App\Enums;

/**
 * Chart of accounts (Documents: ACCOUNT OVERVIEW).
 *
 * Fund accounts that the live system shows as balances (Principal, Interest, Loan fee, Penalty,
 * Reserve, Agent, Insurance, HQ accounts, banks) are modelled as asset accounts holding money.
 * Income, liability, equity and expense accounts record where that money came from or went.
 * Branch-scoped accounts carry a branch_id; HQ accounts have none.
 */
enum Account: string
{
    // Assets — money and receivables
    case Company = 'company_cash';
    case Bank = 'bank';
    /** Investment RESERVE A/C: interest reserve HQ has sent to the company Investment (company level, no branch). */
    case InvestmentReserve = 'investment_reserve';
    case Principal = 'principal';
    case Interest = 'interest';
    case LoanFee = 'loan_fee';
    case Penalty = 'penalty';
    case Reserve = 'reserve';
    case Agent = 'agent';
    case Insurance = 'insurance';
    case TellerCash = 'teller_cash';
    /** Branch PETTY CASH A/C: petty cash HQ has sent to that branch out of interest income; spent only with HQ approval. */
    case PettyCash = 'petty_cash';
    case HqSalaryAdvance = 'hq_salary_advance';
    case HqDisbursement = 'hq_disbursement';
    case HqPenalty = 'hq_penalty';
    case HqInterest = 'hq_interest';
    case HqReserve = 'hq_reserve';
    case HqLoanFee = 'hq_loan_fee';
    case HqSaving = 'hq_saving';
    case LoanReceivable = 'loan_receivable';
    case LoanArrears = 'loan_arrears';
    case LoanDefault = 'loan_default';
    case SalaryAdvanceReceivable = 'salary_advance_receivable';
    case StaffLoanReceivable = 'staff_loan_receivable';
    case StaffAdvanceReceivable = 'staff_advance_receivable';
    case StaffFundCash = 'staff_fund_cash';
    /** ACCOUNT OVERVIEW 17: top-up adjustments (clearing account). */
    case Offset = 'offset';
    /** ACCOUNT OVERVIEW 19: interest due but not yet paid. */
    case OutstandingInterest = 'outstanding_interest';
    /** Penalties charged on overdue loans and not yet paid (accrued income, user decision D9). Not money. */
    case PenaltyReceivable = 'penalty_receivable';

    // Assets — fixed (non-cash) assets, e.g. contributed as capital (config/assets.php maps asset types to these)
    case MotorVehicles = 'motor_vehicles';
    case Equipment = 'equipment';
    case FurnitureFixtures = 'furniture_fixtures';
    case Buildings = 'buildings';
    case Land = 'land';
    case OtherFixedAssets = 'other_fixed_assets';

    // Liabilities
    case Suspense = 'suspense';
    case SavingsDeposits = 'savings_deposits';
    case StaffPayable = 'staff_payable';
    case StaffFund = 'staff_fund';
    case DividendPayable = 'dividend_payable';
    /** Commission allocated from a closed month's profit, not yet recognised in an approved payroll (D1). */
    case CommissionPayable = 'commission_payable';

    // Equity
    case Capital = 'capital';
    case RetainedProfit = 'retained_profit';
    /** Profit reinvested into branch principal by a dividend declaration — not stakeholder capital (spec §15, Rule 13, D4). */
    case ReinvestedProfit = 'reinvested_profit';
    /** Reserve cut from loan interest (spec §6, D6): equity, never income. */
    case InterestReserve = 'interest_reserve';
    /** Insurance income moved out of distributable profit at month end (D7). */
    case InsuranceReserve = 'insurance_reserve';
    /**
     * What customers still owed the old system when their loans and salary advances were imported as opening balances
     * (legacy import): the other side of the LOAN / SALARY ADVANCE RECEIVABLE they bring in. Kept apart from Capital so
     * old-system receivables never show as shareholders' investment.
     */
    case LegacyOpeningBalance = 'legacy_opening_balance';

    // Income
    case InterestIncome = 'interest_income';

    /** Profit on salary advances (specification §9): its own income category, never subject to the 20% interest reserve. */
    case SalaryAdvanceIncome = 'salary_advance_income';
    case FeeIncome = 'fee_income';
    case PenaltyIncome = 'penalty_income';
    case InsuranceIncome = 'insurance_income';
    case RecoveryIncome = 'recovery_income';

    // Expenses
    case OperatingExpense = 'operating_expense';
    case SalaryExpense = 'salary_expense';
    case CommissionExpense = 'commission_expense';
    case AllowanceExpense = 'allowance_expense';
    case WriteOffExpense = 'write_off_expense';
    case BankCharges = 'bank_charges';

    public function type(): string
    {
        return match ($this) {
            self::Suspense, self::SavingsDeposits, self::StaffPayable, self::StaffFund, self::DividendPayable, self::CommissionPayable => 'liability',
            self::Capital, self::RetainedProfit, self::ReinvestedProfit, self::InterestReserve, self::InsuranceReserve, self::LegacyOpeningBalance => 'equity',
            self::InterestIncome, self::SalaryAdvanceIncome, self::FeeIncome, self::PenaltyIncome, self::InsuranceIncome, self::RecoveryIncome => 'income',
            self::OperatingExpense, self::SalaryExpense, self::CommissionExpense, self::AllowanceExpense, self::WriteOffExpense, self::BankCharges => 'expense',
            default => 'asset',
        };
    }

    /**
     * Assets and expenses increase with debits; liabilities, equity and income with credits.
     */
    public function isDebitNormal(): bool
    {
        return in_array($this->type(), ['asset', 'expense'], true);
    }

    public function code(): string
    {
        return match ($this) {
            self::Company => '1000', self::Bank => '1010', self::InvestmentReserve => '1020', self::Principal => '1100', self::Interest => '1110',
            self::LoanFee => '1120', self::Penalty => '1130', self::Reserve => '1140', self::Agent => '1150',
            self::Insurance => '1160', self::TellerCash => '1170', self::PettyCash => '1180', self::HqSalaryAdvance => '1200',
            self::HqDisbursement => '1210', self::HqPenalty => '1220', self::HqInterest => '1230',
            self::HqReserve => '1240', self::HqLoanFee => '1250', self::HqSaving => '1260',
            self::LoanReceivable => '1300', self::LoanArrears => '1310', self::LoanDefault => '1320',
            self::SalaryAdvanceReceivable => '1330', self::StaffLoanReceivable => '1340', self::StaffAdvanceReceivable => '1350', self::StaffFundCash => '1360',
            self::Offset => '1370', self::OutstandingInterest => '1380', self::PenaltyReceivable => '1390',
            self::MotorVehicles => '1500', self::Equipment => '1510', self::FurnitureFixtures => '1520',
            self::Buildings => '1530', self::Land => '1540', self::OtherFixedAssets => '1550',
            self::Suspense => '2000', self::SavingsDeposits => '2010', self::StaffPayable => '2020',
            self::StaffFund => '2030', self::DividendPayable => '2040', self::CommissionPayable => '2050',
            self::Capital => '3000', self::ReinvestedProfit => '3010', self::InterestReserve => '3020', self::InsuranceReserve => '3030',
            self::RetainedProfit => '3100', self::LegacyOpeningBalance => '3900',
            self::InterestIncome => '4000', self::FeeIncome => '4010', self::PenaltyIncome => '4020',
            self::InsuranceIncome => '4030', self::RecoveryIncome => '4040', self::SalaryAdvanceIncome => '4050',
            self::OperatingExpense => '5000', self::SalaryExpense => '5100', self::CommissionExpense => '5110',
            self::AllowanceExpense => '5120', self::WriteOffExpense => '5200', self::BankCharges => '5300',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Principal => 'PRINCIPAL A/C',
            self::Interest => 'INTEREST A/C',
            self::Reserve => 'RESERVE A/C',
            self::LoanFee => 'LOAN FEE A/C',
            self::Penalty => 'PENALTY A/C',
            self::Agent => 'AGENT A/C',
            self::Insurance => 'INSURANCE A/C',
            self::TellerCash => 'TELLER CASH A/C',
            self::PettyCash => 'PETTY CASH A/C',
            self::Bank => 'BANK',
            self::HqSalaryAdvance => 'SALARY ADVANCE ACCOUNT',
            self::HqDisbursement => 'DISBURSEMENT ACCOUNT',
            self::HqPenalty => 'PENALTY ACCOUNT',
            self::HqInterest => 'INTEREST ACCOUNT',
            self::HqReserve => 'RESERVE ACCOUNT',
            self::HqLoanFee => 'LOAN FEE ACCOUNT',
            self::HqSaving => 'SAVING ACCOUNT',
            self::Company => 'COMPANY ACCOUNT',
            self::InvestmentReserve => 'INVESTMENT RESERVE A/C',
            self::LoanReceivable => 'LOAN RECEIVABLE',
            self::LoanArrears => 'LOAN ARREARS',
            self::LoanDefault => 'DEFAULT LOANS',
            self::SalaryAdvanceReceivable => 'SALARY ADVANCE RECEIVABLE',
            self::StaffLoanReceivable => 'STAFF LOAN RECEIVABLE',
            self::StaffAdvanceReceivable => 'STAFF ADVANCE RECEIVABLE',
            self::StaffFundCash => 'STAFF FUND A/C',
            self::Offset => 'OFFSET ACCOUNT',
            self::OutstandingInterest => 'OUTSTANDING INTEREST',
            self::PenaltyReceivable => 'PENALTY RECEIVABLE',
            self::MotorVehicles => 'MOTOR VEHICLES',
            self::Equipment => 'EQUIPMENT & ELECTRONICS',
            self::FurnitureFixtures => 'FURNITURE & FIXTURES',
            self::Buildings => 'BUILDINGS',
            self::Land => 'LAND',
            self::OtherFixedAssets => 'OTHER FIXED ASSETS',
            self::Suspense => 'SUSPENSE ACCOUNT',
            self::SavingsDeposits => 'CUSTOMER SAVINGS',
            self::StaffPayable => 'STAFF PAYABLE',
            self::StaffFund => 'STAFF FUND',
            self::DividendPayable => 'DIVIDEND ACCOUNT',
            self::CommissionPayable => 'COMMISSION PAYABLE',
            self::Capital => 'CAPITAL ACCOUNT',
            self::ReinvestedProfit => 'REINVESTED PROFIT',
            self::InterestReserve => 'INTEREST RESERVE',
            self::InsuranceReserve => 'INSURANCE RESERVE',
            self::LegacyOpeningBalance => 'OLD SYSTEM OPENING BALANCE',
            self::RetainedProfit => 'PROFIT ACCOUNT',
            self::InterestIncome => 'INTEREST INCOME',
            self::SalaryAdvanceIncome => 'SALARY ADVANCE INCOME',
            self::FeeIncome => 'FEE INCOME',
            self::PenaltyIncome => 'PENALTY INCOME',
            self::InsuranceIncome => 'INSURANCE INCOME',
            self::RecoveryIncome => 'RECOVERED LOANS',
            self::OperatingExpense => 'EXPENSES',
            self::SalaryExpense => 'SALARY EXPENSE',
            self::CommissionExpense => 'COMMISSION EXPENSE',
            self::AllowanceExpense => 'ALLOWANCE EXPENSE',
            self::WriteOffExpense => 'WRITE-OFF EXPENSE',
            self::BankCharges => 'BANK CHARGES',
        };
    }

    /**
     * Fixed (non-cash) asset accounts: excluded from cash positions and shown as "Fixed assets" on the Balance Sheet.
     *
     * @return list<self>
     */
    public static function fixedAssets(): array
    {
        return [self::MotorVehicles, self::Equipment, self::FurnitureFixtures, self::Buildings, self::Land, self::OtherFixedAssets];
    }

    /**
     * The accounts HQ itself holds money in. HQ runs no loan book of its own — customers borrow at a branch and HQ funds the
     * disbursement — so the penalty and loan fee a loan produces belong to the branch-tagged {@see self::Penalty} and
     * {@see self::LoanFee} pools, and HQ has no account of its own for either.
     *
     * @return array<int, self>
     */
    public static function hqAccounts(): array
    {
        return [self::HqSalaryAdvance, self::HqDisbursement, self::HqInterest, self::HqReserve, self::HqSaving];
    }
}

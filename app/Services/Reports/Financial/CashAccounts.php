<?php

namespace App\Services\Reports\Financial;

use App\Enums\Account;
use App\Enums\HqFund;
use App\Models\BankAccount;
use App\Models\Company;
use App\Services\Ledger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The one definition of which ledger accounts hold money, grouped the way the reports present them.
 *
 * Money = every asset account except receivables, adjustment assets and fixed assets. Different screens legitimately show
 * different subsets (e.g. a branch cash book excludes bank accounts, the dashboard shows the company account and bank
 * accounts only), but they take the accounts from here and say which groups are included.
 */
class CashAccounts
{
    /**
     * Asset accounts that do not hold money: loan and staff receivables, offsets/accruals and fixed assets.
     *
     * @var list<Account>
     */
    public const NON_CASH_ASSETS = [
        Account::LoanReceivable, Account::LoanArrears, Account::LoanDefault, Account::OutstandingInterest, Account::PenaltyReceivable,
        Account::SalaryAdvanceReceivable, Account::StaffLoanReceivable, Account::StaffAdvanceReceivable, Account::Offset,
        Account::MotorVehicles, Account::Equipment, Account::FurnitureFixtures, Account::Buildings, Account::Land, Account::OtherFixedAssets,
    ];

    /**
     * Money account groups in presentation order: key → [label, accounts].
     *
     * @var array<string, array{0: string, 1: list<Account>}>
     */
    public const GROUPS = [
        'company_cash' => ['Company Cash — COMPANY ACCOUNT', [Account::Company]],
        'bank' => ['Bank accounts', [Account::Bank]],
        'investment_reserve' => ['Investment RESERVE A/C — reserve sent by HQ', [Account::InvestmentReserve]],
        // HqPenalty and HqLoanFee are legacy: HQ runs no loan book of its own, so nothing posts to them any more
        // ({@see Account::hqAccounts()}). They stay in the group so any historical balance is still counted as money.
        'hq_accounts' => ['HQ accounts (salary advance, disbursement, interest, reserve, saving)', [
            Account::HqSalaryAdvance, Account::HqDisbursement, Account::HqPenalty, Account::HqInterest, Account::HqReserve, Account::HqLoanFee, Account::HqSaving,
        ]],
        'lending_cash' => ['Lending cash — PRINCIPAL A/C (HQ funds every loan)', [Account::Principal]],
        'branch_income_funds' => ['Branch income funds — INTEREST, LOAN FEE, PENALTY, RESERVE, INSURANCE A/C', [
            Account::Interest, Account::LoanFee, Account::Penalty, Account::Reserve, Account::Insurance,
        ]],
        'branch_petty_cash' => ['Branch PETTY CASH A/C — petty cash sent by HQ', [Account::PettyCash]],
        'teller_and_agent' => ['Teller cash and agent accounts', [Account::TellerCash, Account::Agent]],
        'staff_fund_cash' => ['Staff fund cash', [Account::StaffFundCash]],
    ];

    public function __construct(private readonly Ledger $ledger) {}

    /**
     * Every money-holding account, optionally without bank accounts (cash in hand only).
     *
     * @return list<Account>
     */
    public static function moneyAccounts(bool $includeBank = true): array
    {
        return array_values(array_filter(
            Account::cases(),
            fn (Account $account): bool => $account->type() === 'asset'
                && ! in_array($account, self::NON_CASH_ASSETS, true)
                && ($includeBank || $account !== Account::Bank),
        ));
    }

    /**
     * Current balance of each money group, company-wide (all branches and HQ).
     *
     * @return list<array{key: string, label: string, accounts: list<string>, amount: float}>
     */
    public function breakdown(Company|int $company): array
    {
        $companyId = $company instanceof Company ? (int) $company->id : $company;
        $balances = DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.company_id', $companyId)
            ->whereIn('accounts.key', array_map(fn (Account $account): string => $account->value, self::moneyAccounts()))
            ->groupBy('accounts.key')
            ->selectRaw('accounts.key AS account_key, COALESCE(SUM(journal_lines.debit), 0) - COALESCE(SUM(journal_lines.credit), 0) AS amount')
            ->pluck('amount', 'account_key');

        $rows = [];
        foreach (self::GROUPS as $key => [$label, $accounts]) {
            $rows[] = [
                'key' => $key,
                'label' => $label,
                'accounts' => array_map(fn (Account $account): string => $account->value, $accounts),
                'amount' => round(array_sum(array_map(fn (Account $account): float => (float) ($balances[$account->value] ?? 0), $accounts)), 2) + 0.0,
            ];
        }

        return $rows;
    }

    /**
     * Sum of the given groups of a breakdown.
     *
     * @param  list<array{key: string, amount: float}>  $breakdown
     * @param  list<string>|null  $keys  null = every group
     */
    public static function total(array $breakdown, ?array $keys = null): float
    {
        return round(array_sum(array_column(array_filter($breakdown, fn (array $row): bool => $keys === null || in_array($row['key'], $keys, true)), 'amount')), 2) + 0.0;
    }

    /**
     * HQ reserve: all interest reserve belongs to HQ, so it is every branch RESERVE A/C (the branch figure is only a report of
     * what the branch generated) plus the HQ RESERVE ACCOUNT. Reserve HQ has sent to the Investment RESERVE A/C is not included.
     */
    public function hqReserve(Company|int $company, ?CarbonInterface $until = null): float
    {
        return round($this->ledger->balance($company, Account::Reserve, until: $until, allBranches: true) + $this->ledger->balance($company, Account::HqReserve, until: $until), 2) + 0.0;
    }

    /**
     * Where the HQ reserve is held in the ledger, largest first: [account, branch id|null, balance] for every reserve account
     * with money.
     *
     * @return list<array{account: Account, branch: int|null, balance: float}>
     */
    public function hqReserveHoldings(Company|int $company): array
    {
        return $this->holdings($company, [Account::Reserve, Account::HqReserve]);
    }

    /**
     * Where an HQ Account List row is held in the ledger, largest first — one row can be a pool of branch accounts plus
     * HQ's own ({@see HqFund}), and money is drawn from each in proportion to what it holds.
     *
     * @return list<array{account: Account, branch: int|null, balance: float}>
     */
    public function fundHoldings(Company|int $company, HqFund $fund): array
    {
        return $this->holdings($company, $fund->accounts());
    }

    /**
     * Balances of the given accounts per branch, largest first, ignoring accounts without money.
     *
     * @param  list<Account>  $accounts
     * @return list<array{account: Account, branch: int|null, balance: float}>
     */
    private function holdings(Company|int $company, array $accounts): array
    {
        $companyId = $company instanceof Company ? (int) $company->id : $company;

        return DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.company_id', $companyId)
            ->whereIn('accounts.key', array_map(fn (Account $account): string => $account->value, $accounts))
            ->groupBy('accounts.key', 'accounts.branch_id')
            ->selectRaw('accounts.key AS account_key, accounts.branch_id, COALESCE(SUM(journal_lines.debit), 0) - COALESCE(SUM(journal_lines.credit), 0) AS amount')
            ->get()
            ->map(fn (object $row): array => ['account' => Account::from($row->account_key), 'branch' => $row->branch_id === null ? null : (int) $row->branch_id, 'balance' => round((float) $row->amount, 2)])
            ->filter(fn (array $row): bool => $row['balance'] > 0)
            ->sortByDesc('balance')
            ->values()
            ->all();
    }

    /**
     * HQ interest: income belongs to HQ, so it is every branch INTEREST A/C plus the HQ INTEREST ACCOUNT. This is what petty
     * cash sent to branches is funded from.
     */
    public function hqInterest(Company|int $company, ?CarbonInterface $until = null): float
    {
        return round($this->ledger->balance($company, Account::Interest, until: $until, allBranches: true) + $this->ledger->balance($company, Account::HqInterest, until: $until), 2) + 0.0;
    }

    /**
     * Where the HQ interest is held, largest first.
     *
     * @return list<array{account: Account, branch: int|null, balance: float}>
     */
    public function hqInterestHoldings(Company|int $company): array
    {
        return $this->holdings($company, [Account::Interest, Account::HqInterest]);
    }

    /**
     * "Company Account List": the COMPANY ACCOUNT followed by each bank account (HQ pools and branch funds are not included).
     *
     * @return array<string, float>
     */
    public function companyAccounts(Company|int $company): array
    {
        $companyId = $company instanceof Company ? (int) $company->id : $company;
        $balances = ['Company A/C' => $this->ledger->balance($companyId, Account::Company) + 0.0];

        foreach (BankAccount::where('company_id', $companyId)->orderBy('id')->get() as $bankAccount) {
            $balances[$bankAccount->name] = $this->ledger->balance($companyId, Account::Bank, bankAccount: $bankAccount) + 0.0;
        }

        return $balances;
    }
}

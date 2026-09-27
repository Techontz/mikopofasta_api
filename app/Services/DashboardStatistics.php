<?php

namespace App\Services;

use App\Enums\Account;
use App\Enums\Duration;
use App\Enums\LoanStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Capital;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\ExpenseRequest;
use App\Models\FloatTransfer;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\Payment;
use App\Models\Penalty;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvancePayment;
use App\Models\Saving;
use App\Services\Reports\Financial\CashAccounts;
use App\Services\Reports\Financial\ProfitLossReport;
use App\Services\Reports\PortfolioReports;
use App\Services\Reports\ReportScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DashboardStatistics
{
    /**
     * Keys of {@see today()} that describe company money; hidden from branch- and zone-scoped employees.
     */
    public const COMPANY_MONEY_MOVEMENTS = ['capital_received', 'float_to_hq', 'principal_transfers_out', 'expenses_paid_from_bank'];

    public function __construct(
        private readonly Ledger $ledger,
        private readonly ProfitLossReport $profitLoss,
        private readonly CashAccounts $cash,
        private readonly AccessControl $access,
        private readonly PortfolioReports $portfolio,
    ) {}

    /**
     * @return array<string, float>
     */
    public function headerAccounts(Company $company): array
    {
        return [
            'Loan Fee A/c' => $this->ledger->balance($company, Account::LoanFee, allBranches: true),
            'Penalty A/c' => $this->ledger->balance($company, Account::Penalty, allBranches: true),
            'Interest A/c' => $this->ledger->balance($company, Account::Interest, allBranches: true),
            'Reserve A/c' => $this->ledger->balance($company, Account::Reserve, allBranches: true),
        ];
    }

    /**
     * Stat cards. The green card is:
     *  - the Investment ({@see accountBalances()}: COMPANY ACCOUNT + bank accounts + Investment reserve + assets) for a
     *    company-wide employee who may see capital — the owners' position, never shown to HQ;
     *  - HQ funds ({@see hqFunds()}: the PRINCIPAL A/C the company floated to HQ plus the HQ income pools) for every other
     *    company-wide employee (HQ, Finance);
     *  - the PETTY CASH A/C of their branches ({@see pettyCash()}) for branch- and zone-scoped employees — the only money a
     *    branch holds. Their disbursed today, total loan outstanding and default loan cover those branches only.
     *
     * @param  list<int>|null  $branchIds
     * @return array{account_balance: float, account_balance_title: string, account_balance_label: string, loan_withdrawal: float, loan_outstanding: float, default_loan: float}
     */
    public function cards(Company $company, CarbonImmutable $today, ?array $branchIds = null, bool $investment = true): array
    {
        $inBranches = fn (Builder $query): Builder => $branchIds === null ? $query : $query->whereIn('branch_id', $branchIds);

        $green = match (true) {
            $branchIds !== null => ['Petty Cash', 'Sent by HQ — spent only with HQ approval', $this->pettyCash($company, $branchIds)],
            $investment => ['Account Balance', 'Company A/C + banks + reserve + assets', round(array_sum($this->accountBalances($company)), 2)],
            default => ['HQ Funds', 'Operation principal + income + fund + reserve', $this->hqFundsTotal($company)],
        };

        return [
            'account_balance' => $green[2],
            'account_balance_title' => $green[0],
            'account_balance_label' => $green[1],
            'loan_withdrawal' => (float) $inBranches(LoanTransaction::where('company_id', $company->id))->where('type', 'withdrawal')->whereNull('reversed_at')->whereDate('transaction_date', $today)->sum('amount'),
            // Total Loan Outstanding: exactly the Loan Portfolio report's figure (principal + interest + penalty still owed on
            // active, overdue and default loans; written-off loans excluded), for the employee's branches.
            'loan_outstanding' => (float) $this->portfolio->portfolio(new ReportScope((int) $company->id, $branchIds))['summary']['outstanding_total'],
            'default_loan' => $inBranches(Loan::where('company_id', $company->id))->status(LoanStatus::Default)->get()->sum(fn (Loan $loan): float => $loan->remaining_amount),
        ];
    }

    /**
     * PETTY CASH A/C balance of the given branches: petty cash HQ has sent them out of interest income, which they spend only
     * on expenses HQ approves. It is the only money a branch holds.
     *
     * @param  list<int>  $branchIds
     */
    public function pettyCash(Company $company, array $branchIds): float
    {
        return round(array_sum(array_map(fn (int $branchId): float => $this->ledger->balance($company, Account::PettyCash, $branchId), $branchIds)), 2) + 0.0;
    }

    /**
     * HQ Account List rows listed but never added to the total: UNMATCHED is money received that is not HQ's until it is matched
     * to a loan (it may still be refunded); SAVINGS is held for the customers who deposited it; PROFIT and DIVIDENDS are shares of OPERATION INCOME, not separate money.
     */
    public const HQ_CLAIM_ROWS = ['UNMATCHED', 'SAVINGS', 'PROFIT', 'DIVIDENDS'];

    /**
     * HQ funds — what HQ and Finance see instead of the owners' Investment: exactly six accounts (user ruling 2026-09-17),
     * plus UNMATCHED (user request 2026-09-17), every one listed even when empty.
     *  - OPERATION PRINCIPAL: the lending money (PRINCIPAL A/C) the company floated to HQ;
     *  - OPERATION INCOME: interest (after the 20% reserve), loan fee and penalty — branch pools plus HQ's own accounts,
     *    one figure (spec §5, presentation only);
     *  - FUND: the single STAFF FUND A/C (spec §25);
     *  - RESERVE: the branch reserve pools plus the HQ reserve;
     *  - UNMATCHED: money received but not yet matched to a loan — the unallocated amount in Payments → Suspense Account;
     *  - SAVINGS: customer savings the company holds (SAVING A/C), what Savings → Savings Balance shows;
     *  - PROFIT: profit closed from past months and not yet distributed (RETAINED PROFIT);
     *  - DIVIDENDS: dividends declared and not yet paid (DIVIDEND PAYABLE).
     *
     * UNMATCHED, SAVINGS, PROFIT and DIVIDENDS are not in the total ({@see HQ_CLAIM_ROWS}): unmatched money is not HQ's until it is
     * allocated, savings belong to the customers, and profit and dividends still sit in OPERATION INCOME until they are reinvested or paid.
     *
     * @return array<string, float>
     */
    public function hqFunds(Company $company): array
    {
        $pool = fn (Account ...$accounts): float => round(array_sum(array_map(
            fn (Account $account): float => $this->ledger->balance($company, $account, allBranches: true), $accounts
        )), 2) + 0.0;

        return [
            'OPERATION PRINCIPAL' => $pool(Account::Principal),
            'OPERATION INCOME' => $pool(Account::Interest, Account::HqInterest, Account::LoanFee, Account::HqLoanFee, Account::Penalty, Account::HqPenalty),
            'FUND' => $pool(Account::StaffFundCash),
            'RESERVE' => $pool(Account::Reserve, Account::HqReserve),
            'UNMATCHED' => round((float) Payment::where('company_id', $company->id)->whereIn('status', PaymentStatus::values(...PaymentStatus::suspense()))->get()->sum('unallocated_amount'), 2) + 0.0,
            'SAVINGS' => $pool(Account::HqSaving),
            'PROFIT' => $pool(Account::RetainedProfit),
            'DIVIDENDS' => $pool(Account::DividendPayable),
        ];
    }

    /**
     * The HQ Funds card: the money rows of {@see hqFunds()} — PROFIT and DIVIDENDS are already inside OPERATION INCOME.
     *
     * @param  array<string, float>|null  $rows
     */
    public function hqFundsTotal(Company $company, ?array $rows = null): float
    {
        $rows ??= $this->hqFunds($company);

        return round(array_sum(array_diff_key($rows, array_flip(self::HQ_CLAIM_ROWS))), 2) + 0.0;
    }

    /**
     * Operating Income (specification §5 and §45): ONE central pool for approved operating expenses, shown as one total with
     * the income categories it came from. The categories are sources, never extra cash on top of the total — the rows add
     * up to it exactly. The ledger keeps an INTEREST, LOAN FEE and PENALTY A/C (user ruling: presentation only), so the total
     * is their sum; interest is already net of the 20% reserve, which is not operating income (§6).
     *
     * @return array{total: float, sources: list<array{key: string, label: string, amount: float}>}
     */
    public function operatingIncome(Company $company): array
    {
        $pool = fn (Account ...$accounts): float => round(array_sum(array_map(
            fn (Account $account): float => $this->ledger->balance($company, $account, allBranches: true), $accounts
        )), 2) + 0.0;

        $sources = [
            ['key' => 'interest', 'label' => 'Interest (after 20% reserve)', 'amount' => $pool(Account::Interest, Account::HqInterest)],
            ['key' => 'loan_fee', 'label' => 'Loan Fee', 'amount' => $pool(Account::LoanFee, Account::HqLoanFee)],
            ['key' => 'penalty', 'label' => 'Penalty', 'amount' => $pool(Account::Penalty, Account::HqPenalty)],
        ];

        return ['total' => round(array_sum(array_column($sources, 'amount')), 2) + 0.0, 'sources' => $sources];
    }

    /**
     * "Company Account List" (Investment) modal: the company account, each bank account, the Investment RESERVE A/C
     * (only reserve HQ has already sent — it can differ from the HQ reserve), then one Assets row
     * = the total of every fixed asset account (assets contributed as capital).
     *
     * @return array<string, float>
     */
    public function accountBalances(Company $company): array
    {
        $balances = $this->cash->companyAccounts($company) + [
            'Reserve A/C' => $this->ledger->balance($company, Account::InvestmentReserve) + 0.0,
        ];

        $balances['Assets'] = round(array_sum(array_map(fn (Account $asset): float => $this->ledger->balance($company, $asset, allBranches: true), Account::fixedAssets())), 2) + 0.0;

        return $balances;
    }

    /**
     * The memo lines the live account modal prints under TOTAL: what staff still owe on salary advances, and the dividends
     * declared but not yet paid out. Neither is part of the total — one is money still to come back, the other money already
     * promised to the shareholders. DIVIDEND is a company-level liability, so it is the whole company's whatever branches the
     * caller is scoped to.
     *
     * @param  list<int>|null  $branchIds  null = the whole company
     * @return list<array{label: string, amount: float, tone: string}>
     */
    public function accountMemos(Company $company, ?array $branchIds = null): array
    {
        $advances = SalaryAdvance::where('company_id', $company->id)
            ->when($branchIds !== null, fn (Builder $query): Builder => $query->whereIn('branch_id', $branchIds))
            ->where('status', 'active')->withSum('payments', 'amount')->get();
        $advanceRemaining = round($advances->sum(fn (SalaryAdvance $advance): float => $advance->remaining_amount), 2);

        return [
            ['label' => 'Salary advance Remain', 'amount' => $advanceRemaining, 'tone' => 'primary'],
            ['label' => 'Dividend', 'amount' => round($this->ledger->balance($company, Account::DividendPayable, allBranches: true), 2) + 0.0, 'tone' => 'success'],
        ];
    }

    /**
     * "Branch List" modal: one row per real branch (Head Office is not a branch). Every column covers ONE month except PETTY CASH,
     * which is the balance the branch holds now — the only money a branch holds (user ruling 2026-09-17). The monthly columns
     * report what the branch generated for HQ:
     *  - principal_repaid: principal its customers paid back (reversed repayments excluded). Already back in HQ's OPERATION
     *    PRINCIPAL, so a report figure, never extra money;
     *  - interest (after the 20% reserve), loan_fee, penalty and reserve: collected in the month, exactly as the Profit & Loss
     *    report counts them ({@see ProfitLossReport::row()}: reversals net out, month-end closing entries excluded);
     *  - salary_advance: the FULL amount its customers repaid on salary advances in the month (capital + profit, reversed
     *    advances excluded) — a report figure only (user ruling 2026-09-17). The capital is already back in OPERATION
     *    PRINCIPAL and the profit in Salary Advance income; nothing here moves money;
     *  - cash_pending: money the teller collected at the branch in the month (cash, bank or mobile money) that Finance has not yet
     *    verified as banked.
     *
     * @return array{month: string, rows: list<array<string, float|string>>, total: array<string, float>}
     */
    public function branchAccounts(Company $company, ?CarbonImmutable $month = null): array
    {
        $month ??= CarbonImmutable::today();
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();
        $inMonth = [$from->toDateString(), $to->toDateString()];
        $principalRepaid = LoanTransaction::where('company_id', $company->id)->where('type', 'deposit')->whereNull('reversed_at')
            ->whereBetween('transaction_date', $inMonth)->groupBy('branch_id')->selectRaw('branch_id, SUM(principal) as total')->pluck('total', 'branch_id');
        $cashPending = Payment::where('company_id', $company->id)->where('source', Payment::SOURCE_TELLER)
            ->whereIn('status', [PaymentStatus::PendingVerification->value, PaymentStatus::Deposited->value])
            ->whereBetween('paid_on', $inMonth)->groupBy('branch_id')->selectRaw('branch_id, SUM(amount) as total')->pluck('total', 'branch_id');

        $salaryAdvanceRepaid = SalaryAdvancePayment::join('salary_advances', 'salary_advances.id', '=', 'salary_advance_payments.salary_advance_id')
            ->where('salary_advances.company_id', $company->id)->whereNull('salary_advances.reversed_at')->whereNull('salary_advance_payments.reversed_at')
            ->whereBetween('salary_advance_payments.paid_on', $inMonth)->groupBy('salary_advances.branch_id')
            ->selectRaw('salary_advances.branch_id as branch_id, SUM(salary_advance_payments.amount) as total')->pluck('total', 'branch_id');

        $rows = $company->branches()->where('is_head_office', false)->orderBy('id')->get()->map(function (Branch $branch) use ($company, $from, $to, $principalRepaid, $cashPending, $salaryAdvanceRepaid): array {
            $pnl = $this->profitLoss->row($this->profitLoss->companyFigures((int) $company->id, $from, $to, [$branch->id]));

            return [
                'name' => $branch->name,
                'petty_cash' => round($this->ledger->balance($company, Account::PettyCash, $branch), 2) + 0.0,
                'principal_repaid' => round((float) ($principalRepaid[$branch->id] ?? 0), 2),
                'interest' => $pnl['interest_income'],
                'loan_fee' => $pnl['fee_income'],
                'penalty' => $pnl['penalty_income'],
                'reserve' => $pnl['reserve_amount'],
                'salary_advance' => round((float) ($salaryAdvanceRepaid[$branch->id] ?? 0), 2),
                'cash_pending' => round((float) ($cashPending[$branch->id] ?? 0), 2),
            ];
        })->values();

        $total = [];
        foreach (['petty_cash', 'principal_repaid', 'interest', 'loan_fee', 'penalty', 'reserve', 'salary_advance', 'cash_pending'] as $key) {
            $total[$key] = round((float) $rows->sum($key), 2);
        }

        return ['month' => $from->format('F Y'), 'rows' => $rows->all(), 'total' => $total];
    }

    /**
     * Figures for the "Customer type / Today deposit / withdrawal / income / expenses" table.
     *
     * Deposits / withdrawals are cash-book movements (reversed records excluded). Income and expenses come from the ledger
     * (entries dated today, month-end closing entries excluded) with the components of the branch Profit & Loss
     * ({@see ProfitLossReport::row()}): interest net of reserve + loan fees + penalties + recoveries = total income;
     * insurance is no longer used and not shown. Capital received, float sent to HQ and
     * other principal transfers are money movements — never income or expenses (spec Rule 1, §12, §19).
     * $branchIds null = whole company. With branch ids (branch- and zone-scoped employees) every figure covers those branches
     * only and the company money movements (capital, float, principal transfers, expenses paid from the company bank) are null.
     *
     * @param  list<int>|null  $branchIds
     * @return array<string, float|int|null>
     */
    public function today(Company $company, CarbonImmutable $today, ?array $branchIds = null): array
    {
        $inBranches = fn (Builder $query): Builder => $branchIds === null ? $query : $query->whereIn($query->getModel()->qualifyColumn('branch_id'), $branchIds);
        $loanTransactions = fn (string $type, Duration $duration): float => (float) $inBranches(LoanTransaction::where('company_id', $company->id))->where('type', $type)->whereNull('reversed_at')
            ->whereDate('transaction_date', $today)->whereHas('loan', fn ($query) => $query->where('duration', $duration->value))->sum('amount');
        $withdrawals = fn (Duration $duration): float => $loanTransactions('withdrawal', $duration);
        $customers = fn (): Builder => $inBranches(Customer::where('company_id', $company->id));

        $raw = $this->profitLoss->companyFigures((int) $company->id, $today, $today, $branchIds);
        $pnl = $this->profitLoss->row($raw);
        $operatingExpenses = (float) ($raw[Account::OperatingExpense->value] ?? 0);

        $figures = [
            'monthly_customers' => $this->customersWithDuration($company, Duration::Monthly, $branchIds)->count(),
            'weekly_customers' => $this->customersWithDuration($company, Duration::Weekly, $branchIds)->count(),
            'daily_customers' => $this->customersWithDuration($company, Duration::Daily, $branchIds)->count(),
            'groups' => $branchIds === null ? $company->groups()->count() : $customers()->whereNotNull('group_id')->distinct()->count('group_id'),
            'monthly_deposit' => $loanTransactions('deposit', Duration::Monthly),
            'weekly_deposit' => $loanTransactions('deposit', Duration::Weekly),
            'daily_deposit' => $loanTransactions('deposit', Duration::Daily),
            'salary_advance_deposit' => (float) SalaryAdvancePayment::whereHas('salaryAdvance', fn ($query) => $inBranches($query->where('company_id', $company->id)->whereNull('reversed_at')))->whereNull('reversed_at')->whereDate('paid_on', $today)->sum('amount'),
            'monthly_withdrawal' => $withdrawals(Duration::Monthly),
            'weekly_withdrawal' => $withdrawals(Duration::Weekly),
            'daily_withdrawal' => $withdrawals(Duration::Daily),
            'salary_advance_withdrawal' => (float) $inBranches(SalaryAdvance::where('company_id', $company->id))->whereNull('reversed_at')->whereDate('approved_at', $today)->sum('amount'),

            'interest_income' => $pnl['interest_income'],
            'reserve_amount' => $pnl['reserve_amount'],
            'penalty_income' => $pnl['penalty_income'],
            'loan_fee_income' => $pnl['fee_income'],
            'recovery_income' => $pnl['recovery_income'],
            'salary_advance_income' => $pnl['salary_advance_income'],
            'total_income' => $pnl['total_income'],

            'expenses' => round($operatingExpenses, 2),
            'expenses_paid_from_bank' => (float) $inBranches(ExpenseRequest::where('company_id', $company->id))->where('status', 'accepted')->whereNull('reversed_at')
                ->where('paid_from_account', Account::Bank->value)->whereDate('approved_at', $today)->sum('amount'),
            'other_expenses' => round($pnl['expenses'] - $operatingExpenses, 2),
            'total_expenses' => $pnl['expenses'],
            'net_income' => round($pnl['total_income'] - $pnl['expenses'], 2),

            'capital_received' => (float) Capital::where('company_id', $company->id)->active()->where('pay_method', '!=', 'ASSET')
                ->whereRaw('DATE(COALESCE(contributed_at, created_at)) = ?', [$today->toDateString()])->sum('amount'),
            'float_to_hq' => (float) FloatTransfer::where('company_id', $company->id)->where('type', 'company_to_hq')->where('status', 'approved')->whereNull('reversed_at')->whereDate('transfer_date', $today)->sum('amount'),
            'principal_transfers_out' => $this->ledger->movement($company, Account::Principal, $today, $today, inflow: false) - $withdrawals(Duration::Monthly) - $withdrawals(Duration::Weekly) - $withdrawals(Duration::Daily),
            'saving_withdrawal' => (float) $inBranches(Saving::where('company_id', $company->id))->where('type', 'withdrawal')->whereNull('reversed_at')->whereDate('transaction_date', $today)->sum('amount'),
            'all_customers' => $customers()->count(),
        ];

        $figures['principal_transfers_out'] = round(max(0, $figures['principal_transfers_out']), 2);
        if ($branchIds !== null) {
            $figures = array_merge($figures, array_fill_keys(self::COMPANY_MONEY_MOVEMENTS, null));
        }
        $figures['total_deposit'] = round($figures['monthly_deposit'] + $figures['weekly_deposit'] + $figures['daily_deposit'] + $figures['salary_advance_deposit'], 2);
        $figures['total_withdrawal'] = round($figures['monthly_withdrawal'] + $figures['weekly_withdrawal'] + $figures['daily_withdrawal'] + $figures['salary_advance_withdrawal'], 2);

        return $figures;
    }

    /**
     * Finance dashboard KPI blocks computed on the server (the browser only displays them). Branch-scoped employees see
     * their branches only, like the Penalty and Salary Advance pages. A block is null when the employee may not see it.
     *
     * @param  array{penalty: bool, salary_advance: bool, hq_accounts: bool, company_accounts: bool, account_balance?: bool}  $visible
     * @return array<string, mixed>
     */
    public function financeKpis(Employee $employee, array $visible): array
    {
        $company = $employee->company;
        $hq = $visible['hq_accounts'] ? $this->hqAccounts($company) : null;
        $companyAccounts = $visible['company_accounts'] ? $this->accountBalances($company) : null;

        return [
            'penalty' => $visible['penalty'] ? $this->penaltyKpi($employee) : null,
            'salary_advance' => $visible['salary_advance'] ? $this->salaryAdvanceKpis($employee) : null,
            'hq_accounts' => $hq,
            'company_accounts' => $companyAccounts === null ? null : [
                'rows' => collect($companyAccounts)->map(fn (float $balance, string $name): array => ['name' => $name, 'balance' => $balance])->values()->all(),
                'total' => round(array_sum($companyAccounts), 2),
            ],
            'account_balance' => ! ($visible['account_balance'] ?? true) ? null : ($hq !== null
                ? ['label' => 'HQ accounts', 'amount' => $hq['total']]
                : ['label' => 'Company A/C + banks + reserve + assets', 'amount' => round(array_sum($companyAccounts ?? $this->accountBalances($company)), 2)]),
        ];
    }

    /**
     * "Penalty (x%)": penalty money collected (paid part of every penalty, including penalties waived after a part payment)
     * against collected + still outstanding (unwaived penalties' unpaid part) — the same outstanding penalty as
     * {@see LoanService::outstanding()}.
     *
     * @return array{collected: float, remaining: float, due: float, percent: float}
     */
    public function penaltyKpi(Employee $employee): array
    {
        $penalties = fn (): Builder => $this->access->scope(Penalty::query(), $employee);
        $collected = round((float) $penalties()->sum('paid_amount'), 2);
        $remaining = round((float) $penalties()->where('is_waived', false)->whereColumn('paid_amount', '<', 'amount')->selectRaw('COALESCE(SUM(amount - paid_amount), 0) AS remaining')->value('remaining'), 2);

        return self::progress($collected, round($collected + $remaining, 2)) + ['remaining' => $remaining];
    }

    /**
     * Salary advance KPIs over the employee's advances (reversed advances excluded):
     * - received: paid against payable of ACTIVE and DONE advances (Salary Advance Loan Repayment page);
     * - default: ACTIVE advances with a balance whose repayment cycle has ended — cycle end = the 5th of the month after
     *   approval (creation when not yet approved), the rule of the Active Salary Advance page's "old" alert. Percent = unpaid share
     *   of what they owe; collected/due = paid / payable;
     * - customers: active advances, split into new (cycle still running) and old (cycle ended).
     *
     * @return array{received: array{collected: float, due: float, percent: float}, default: array{collected: float, due: float, unpaid: float, percent: float, count: int}, customers: array{active: int, new: int, old: int}}
     */
    public function salaryAdvanceKpis(Employee $employee): array
    {
        $advances = $this->access->scope(SalaryAdvance::query(), $employee)
            ->whereIn('status', ['active', 'done'])
            ->whereNull('reversed_at')
            ->withSum('payments', 'amount')
            ->get()
            ->map(fn (SalaryAdvance $advance): array => [
                'status' => $advance->status,
                'payable' => (float) $advance->total_payable,
                'paid' => $advance->paid_amount,
                'remaining' => $advance->remaining_amount,
                'cycle_ended' => self::repaymentCycleEnded($advance),
            ]);

        $active = $advances->where('status', 'active');
        $old = $active->where('cycle_ended', true);
        $defaulted = $old->where('remaining', '>', 0);

        $defaultDue = round((float) $defaulted->sum('payable'), 2);
        $unpaid = round((float) $defaulted->sum('remaining'), 2);

        return [
            'received' => self::progress(round((float) $advances->sum('paid'), 2), round((float) $advances->sum('payable'), 2)),
            'default' => [
                'collected' => round((float) $defaulted->sum('paid'), 2),
                'due' => $defaultDue,
                'unpaid' => $unpaid,
                'percent' => self::percent($unpaid, $defaultDue),
                'count' => $defaulted->count(),
            ],
            'customers' => ['active' => $active->count(), 'new' => $active->count() - $old->count(), 'old' => $old->count()],
        ];
    }

    /**
     * HQ account balances (HQ Transactions page) and their total. The RESERVE ACCOUNT row is the whole HQ reserve
     * ({@see CashAccounts::hqReserve()}: every branch RESERVE A/C + the HQ RESERVE ACCOUNT).
     *
     * @return array{rows: list<array{account: string, name: string, balance: float}>, total: float}
     */
    public function hqAccounts(Company $company): array
    {
        $rows = array_map(fn (Account $account): array => [
            'account' => $account->value,
            'name' => $account->label(),
            'balance' => ($account === Account::HqReserve ? $this->cash->hqReserve($company) : $this->ledger->balance($company, $account)) + 0.0,
        ], Account::hqAccounts());

        return ['rows' => $rows, 'total' => round(array_sum(array_column($rows, 'balance')), 2)];
    }

    /**
     * Whether a salary advance's repayment cycle (to the 5th of the month after approval) has ended.
     */
    public static function repaymentCycleEnded(SalaryAdvance $advance, ?CarbonImmutable $now = null): bool
    {
        $start = CarbonImmutable::parse($advance->approved_at ?? $advance->created_at);

        return $start->addMonthNoOverflow()->day(5)->lt($now ?? CarbonImmutable::now());
    }

    /**
     * @return array{collected: float, due: float, percent: float}
     */
    public static function progress(float $collected, float $due): array
    {
        return ['collected' => $collected, 'due' => $due, 'percent' => self::percent($collected, $due)];
    }

    /**
     * Percentage rounded to two decimals; 0 when the whole is 0.
     */
    public static function percent(float $part, float $whole): float
    {
        return abs($whole) < 0.005 ? 0.0 : round($part / $whole * 100, 2);
    }

    /**
     * Rows of the customer type summary table (Monthly / Weekly / Day / Group / All Customer); $branchIds limits them to
     * those branches.
     *
     * @param  list<int>|null  $branchIds
     * @return array<int, array<string, mixed>>
     */
    public function customerTypes(Company $company, ?array $branchIds = null): array
    {
        $customers = fn (): Builder => $branchIds === null ? Customer::where('company_id', $company->id) : Customer::where('company_id', $company->id)->whereIn('branch_id', $branchIds);
        $rows = [
            ['label' => 'Monthly', 'route' => 'customers.monthly', 'customers' => $this->customersWithDuration($company, Duration::Monthly, $branchIds)],
            ['label' => 'Weekly', 'route' => 'customers.weekly', 'customers' => $this->customersWithDuration($company, Duration::Weekly, $branchIds)],
            ['label' => 'Day', 'route' => 'customers.daily', 'customers' => $this->customersWithDuration($company, Duration::Daily, $branchIds)],
            ['label' => 'Group', 'route' => 'groups.index', 'customers' => $customers()->where('customer_type', 'group')->get()],
        ];
        $rows[] = ['label' => 'All Customer', 'route' => 'customers.index', 'customers' => $customers()->get()];

        return array_map(fn (array $row): array => $row + [
            'all' => $row['customers']->count(),
            'active' => $row['customers']->where('status', 'open')->count(),
            'pending' => $row['customers']->where('status', 'pending')->count(),
            'close' => $row['customers']->where('status', 'close')->count(),
            'default' => $row['customers']->where('status', 'out')->count(),
            'male' => $row['customers']->where('gender', 'male')->count(),
            'female' => $row['customers']->where('gender', 'female')->count(),
        ], $rows);
    }

    /**
     * @param  list<int>|null  $branchIds
     * @return Collection<int, Customer>
     */
    public function customersWithDuration(Company $company, Duration $duration, ?array $branchIds = null): Collection
    {
        return Customer::where('company_id', $company->id)
            ->when($branchIds !== null, fn (Builder $query) => $query->whereIn('branch_id', $branchIds))
            ->whereHas('loans', fn ($query) => $query->where('duration', $duration->value)->status(LoanStatus::Active, LoanStatus::Overdue, LoanStatus::Default, LoanStatus::Closed, LoanStatus::AwaitingDisbursement))
            ->get();
    }
}

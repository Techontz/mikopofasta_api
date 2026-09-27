<?php

namespace App\Services;

use App\Enums\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\BranchPeriodResult;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JournalLine;
use App\Models\LoanOffset;
use App\Services\Reports\Financial\InterestReserves;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Month-end process (ACCOUNT OVERVIEW "E. MONTH END PROCESS", STAFF COMMISSION §7, OVERVIEW ALL REPORT
 * "Branch Profit & Loss"):
 *
 *   + Interest (reserve already cut) + Fees + Penalties + Recoveries = Total Income
 *   − Expenses (all tagged to the branch)                              = Gross Profit
 *   − Loss brought forward                                             = Net Profit
 *   Net Profit < 0 → carried forward as loss, no commission;
 *   otherwise HQ holds 2 %, the remainder is the distributable profit (commission base).
 *
 * Closing a period posts "Dr Income / Cr Profit Account" (and the reverse for expenses) and locks
 * the period in the ledger.
 *
 * Final rules (RULES_FINAL):
 *  - Rule 2: eligible cash income (interest net of the reserve — including write-off recoveries credited to interest income —
 *    fees, penalties collected, legacy recovery income; insurance excluded) − eligible expenses − loss b/f − HQ 2 % hold =
 *    distributable profit, the only base of commission (10 %) and, after calculated commission, of dividends (90 % → 70/30).
 *  - Rule 3: the interest reserve never reaches profit. The only reserve-related closing line is the legacy reclassification
 *    Dr INTEREST INCOME / Cr INTEREST RESERVE for reserve still inside interest income (legacy repayment entries): both sides are
 *    non-cash (income → reserve equity), it keeps that reserve out of the Profit Account and never touches the branch RESERVE A/C
 *    money. Nothing here moves reserve into profit, principal, dividends or income.
 *  - Rule 14: cash basis — penalty income is read from the ledger as posted (cash collections; legacy accrual entries on older
 *    data are read as posted too), no accrual is assumed.
 *  - Rule 15: legacy INSURANCE INCOME closes to INSURANCE RESERVE; new collections credit INSURANCE RESERVE directly (equity, not in
 *    the income/expense rows) — neither reaches profit.
 *  - Rule 16: the HQ 2 % hold is applied once, here: results store it, closing moves exactly that amount from the branch Profit
 *    Account to the HQ Profit Account; commission, dividends and reports read the stored distributable profit and never re-apply it.
 */
class PeriodClose
{
    /** STAFF COMMISSION §7: "Net Profit → apply HQ 2% hold". */
    public const HQ_HOLD_PERCENT = 2.0;

    /**
     * Expense accounts deducted from branch income.
     *
     * @var list<Account>
     */
    public const EXPENSE_ACCOUNTS = [
        Account::OperatingExpense, Account::SalaryExpense, Account::CommissionExpense,
        Account::AllowanceExpense, Account::WriteOffExpense, Account::BankCharges,
    ];

    public function __construct(private readonly Ledger $ledger) {}

    /**
     * First and last day of the month containing $month.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function monthBounds(CarbonInterface $month): array
    {
        $start = CarbonImmutable::parse($month->toDateString())->startOfMonth();

        return [$start, $start->endOfMonth()->startOfDay()];
    }

    /**
     * Compute (or refresh) the results of an open period for every branch of the company.
     *
     * @throws ValidationException
     */
    public function calculate(Company|int $company, CarbonInterface $month): AccountingPeriod
    {
        $companyId = $company instanceof Model ? (int) $company->getKey() : $company;
        [$start, $end] = $this->monthBounds($month);

        return DB::transaction(function () use ($companyId, $start, $end): AccountingPeriod {
            $period = AccountingPeriod::query()->firstOrCreate(
                ['company_id' => $companyId, 'period_start' => $start->toDateString()],
                ['period_end' => $end->toDateString(), 'status' => AccountingPeriod::STATUS_OPEN],
            );

            if ($period->isClosed()) {
                throw ValidationException::withMessages(['month' => "The period {$start->format('Y-m')} is already closed."]);
            }

            $figures = $this->figures($companyId, $start, $end);
            $previous = AccountingPeriod::query()
                ->where('company_id', $companyId)
                ->whereDate('period_start', '<', $start->toDateString())
                ->orderByDesc('period_start')
                ->first();
            $previousLosses = $previous?->results()->pluck('loss_carried_forward', 'branch_id') ?? collect();

            foreach (Branch::where('company_id', $companyId)->orderBy('id')->get() as $branch) {
                $row = $this->branchResult($figures, $branch->id, (float) ($previousLosses[$branch->id] ?? 0));
                BranchPeriodResult::updateOrCreate(
                    ['accounting_period_id' => $period->id, 'branch_id' => $branch->id],
                    $row,
                );
            }

            return $period->load('results.branch');
        });
    }

    /**
     * Close a finished month: recalculate, post closing entries dated the last day of the month,
     * move the HQ 2 % hold to the HQ profit account and lock the period.
     *
     * @throws ValidationException
     */
    public function close(AccountingPeriod $period, ?Employee $employee = null): AccountingPeriod
    {
        if ($period->isClosed()) {
            throw ValidationException::withMessages(['period' => 'This period is already closed.']);
        }
        if ($period->period_end->toDateString() >= now()->toDateString()) {
            throw ValidationException::withMessages(['period' => 'A period can only be closed after its last day has passed.']);
        }
        $earlierOpen = AccountingPeriod::query()
            ->where('company_id', $period->company_id)
            ->where('status', AccountingPeriod::STATUS_OPEN)
            ->whereDate('period_start', '<', $period->period_start->toDateString())
            ->orderBy('period_start')
            ->first();
        if ($earlierOpen !== null) {
            throw ValidationException::withMessages(['period' => "Close the earlier period {$earlierOpen->period_start->format('Y-m')} first."]);
        }

        return DB::transaction(function () use ($period, $employee): AccountingPeriod {
            $period = $this->calculate((int) $period->company_id, $period->period_start);
            $end = CarbonImmutable::parse($period->period_end->toDateString());
            $label = $period->period_start->format('Y-m');

            foreach ($this->closingLines($period) as $branchId => $lines) {
                $this->ledger->journal($period->company_id, "MONTH END CLOSING {$label}", $lines, $period, $end, $branchId === 0 ? null : $branchId, $employee);
            }

            foreach ($period->results as $result) {
                $hold = (float) $result->hq_hold_amount;
                if ($hold > 0) {
                    $this->ledger->journal($period->company_id, "HQ 2% HOLD {$label} - {$result->branch?->name}", [
                        ['account' => Account::RetainedProfit, 'branch' => $result->branch_id, 'debit' => $hold],
                        ['account' => Account::RetainedProfit, 'credit' => $hold],
                    ], $period, $end, $result->branch_id, $employee);
                }
            }

            $period->update([
                'status' => AccountingPeriod::STATUS_CLOSED,
                'closed_by' => $employee?->id,
                'closed_at' => now(),
            ]);

            return $period->fresh(['results.branch', 'closedBy']);
        });
    }

    /**
     * Company-wide profit of a month: branch and HQ income (interest net of reserve, fees,
     * penalties, recoveries) less all expenses. Used by the dividend declaration (70/30 split).
     *
     * Inferred: HQ-level (untagged) income and expenses belong to the company result too.
     */
    public function companyProfit(Company|int $company, CarbonInterface $month): float
    {
        return $this->companyResult($company, $month)['gross_profit'];
    }

    /**
     * Company-wide monthly figures (same columns as a branch result, without loss carry forward).
     *
     * @return array{interest_income: float, reserve_amount: float, salary_advance_income: float, fee_income: float, penalty_income: float, recovery_income: float, total_income: float, expenses: float, gross_profit: float}
     */
    public function companyResult(Company|int $company, CarbonInterface $month): array
    {
        $companyId = $company instanceof Model ? (int) $company->getKey() : $company;
        [$start, $end] = $this->monthBounds($month);
        $figures = $this->figures($companyId, $start, $end);

        $sum = ['interest_income' => 0.0, 'reserve_amount' => 0.0, 'salary_advance_income' => 0.0, 'fee_income' => 0.0, 'penalty_income' => 0.0, 'recovery_income' => 0.0, 'total_income' => 0.0, 'expenses' => 0.0, 'gross_profit' => 0.0];
        foreach ($figures['branches'] as $branchId) {
            $row = $this->incomeFigures($figures, $branchId);
            foreach ($sum as $key => $value) {
                $sum[$key] = round($value + $row[$key], 2);
            }
        }

        return $sum;
    }

    /**
     * Net debit/credit per (branch, account key) for the period, excluding closing entries, plus the reserve cut from
     * interest ({@see InterestReserves}: `reserve` = all reserve, `legacy_reserve` = the part still inside interest income).
     *
     * @return array{totals: Collection<string, object>, reserve: Collection<int|string, float>, legacy_reserve: Collection<int|string, float>, offsets: Collection<int|string, float>, branches: list<int>}
     */
    private function figures(int $companyId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $base = fn (): Builder => JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.company_id', $companyId)
            ->whereBetween('journal_entries.entry_date', [$start->toDateString(), $end->toDateString()])
            ->where(fn (Builder $query) => $query->whereNull('journal_entries.source_type')->orWhere('journal_entries.source_type', '!=', (new AccountingPeriod)->getMorphClass()));

        $totals = $base()
            ->whereIn('accounts.type', ['income', 'expense'])
            ->groupBy('accounts.branch_id', 'accounts.key')
            ->selectRaw('COALESCE(accounts.branch_id, 0) AS branch, accounts.key AS account_key, SUM(journal_lines.debit) AS debits, SUM(journal_lines.credit) AS credits')
            ->toBase()
            ->get()
            ->keyBy(fn (object $row): string => $row->branch.':'.$row->account_key);

        $reserves = InterestReserves::byBranch(fn () => $base()->toBase());
        $reserve = collect($reserves['total']);
        $legacyReserve = collect($reserves['legacy']);

        // Offset settled in the period (§15): old debt cleared by a top-up, never collected in cash.
        $offsets = LoanOffset::query()->active()
            ->where('company_id', $companyId)
            ->whereBetween('settled_on', [$start->toDateString(), $end->toDateString()])
            ->groupBy('branch_id')
            ->selectRaw('COALESCE(branch_id, 0) AS branch, SUM(amount) AS total')
            ->toBase()->get()->pluck('total', 'branch')->map(fn ($total): float => round((float) $total, 2));

        $branches = $totals->pluck('branch')->merge($reserve->keys())->merge($offsets->keys())->map(fn ($id): int => (int) $id)->unique()->values()->all();

        return ['totals' => $totals, 'reserve' => $reserve, 'legacy_reserve' => $legacyReserve, 'offsets' => $offsets, 'branches' => $branches];
    }

    /**
     * @param  array{totals: Collection<string, object>, reserve: Collection<int|string, float>, legacy_reserve: Collection<int|string, float>, offsets: Collection<int|string, float>, branches: list<int>}  $figures
     * @return array{interest_income: float, reserve_amount: float, salary_advance_income: float, fee_income: float, penalty_income: float, recovery_income: float, total_income: float, expenses: float, gross_profit: float}
     */
    private function incomeFigures(array $figures, int $branchId): array
    {
        $net = function (Account $account) use ($figures, $branchId): float {
            $row = $figures['totals']->get($branchId.':'.$account->value);
            if ($row === null) {
                return 0.0;
            }
            $difference = (float) $row->credits - (float) $row->debits;

            return round($account->isDebitNormal() ? -$difference : $difference, 2);
        };

        $reserve = (float) ($figures['reserve'][$branchId] ?? 0);
        $interest = round($net(Account::InterestIncome) - (float) ($figures['legacy_reserve'][$branchId] ?? 0), 2);
        $salaryAdvance = $net(Account::SalaryAdvanceIncome);
        $fee = $net(Account::FeeIncome);
        $penalty = $net(Account::PenaltyIncome);
        $recovery = $net(Account::RecoveryIncome);
        $total = round($interest + $salaryAdvance + $fee + $penalty + $recovery, 2);
        $expenses = round(array_sum(array_map($net, self::EXPENSE_ACCOUNTS)), 2);

        return [
            'interest_income' => $interest,
            'reserve_amount' => $reserve,
            'salary_advance_income' => $salaryAdvance,
            'fee_income' => $fee,
            'penalty_income' => $penalty,
            'recovery_income' => $recovery,
            'total_income' => $total,
            'expenses' => $expenses,
            'gross_profit' => round($total - $expenses, 2),
        ];
    }

    /**
     * @param  array{totals: Collection<string, object>, reserve: Collection<int|string, float>, legacy_reserve: Collection<int|string, float>, offsets: Collection<int|string, float>, branches: list<int>}  $figures
     * @return array<string, float|bool>
     */
    private function branchResult(array $figures, int $branchId, float $lossBroughtForward): array
    {
        $row = $this->incomeFigures($figures, $branchId);
        $net = round($row['gross_profit'] - $lossBroughtForward, 2);
        $hold = $net > 0 ? round($net * self::HQ_HOLD_PERCENT / 100, 2) : 0.0;
        $distributable = $net > 0 ? round($net - $hold, 2) : 0.0;

        return $row + [
            'loss_brought_forward' => $lossBroughtForward,
            'net_profit' => $net,
            'loss_carried_forward' => $net < 0 ? -$net : 0.0,
            'hq_hold_percent' => self::HQ_HOLD_PERCENT,
            'hq_hold_amount' => $hold,
            'distributable_profit' => $distributable,
            // Stored with the period so a later top-up can never move a commission that has already been calculated.
            'offset_amount' => min($distributable, (float) ($figures['offsets'][$branchId] ?? 0)),
            'commission_eligible' => $distributable > 0,
        ];
    }

    /**
     * Closing lines per branch (0 = HQ): every income and expense account row is brought to zero.
     *
     *  - INSURANCE INCOME is not distributable profit: it closes to INSURANCE RESERVE (user decision D7).
     *  - Reserve still inside INTEREST INCOME (legacy repayment entries, {@see InterestReserves}) is not income: that part
     *    closes to INTEREST RESERVE (user decision D6); new repayment entries already credit INTEREST RESERVE directly.
     *  - Everything else closes to the branch PROFIT ACCOUNT, which therefore receives exactly the branch gross profit.
     *
     * Periods closed before these rules (April–June 2026) keep their original closing entries.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function closingLines(AccountingPeriod $period): array
    {
        $rows = JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.company_id', $period->company_id)
            ->whereBetween('journal_entries.entry_date', [$period->period_start->toDateString(), $period->period_end->toDateString()])
            ->whereIn('accounts.type', ['income', 'expense'])
            ->groupBy('accounts.id', 'accounts.key', 'accounts.branch_id', 'accounts.bank_account_id', 'accounts.employee_id', 'accounts.expense_type_id')
            ->selectRaw('accounts.key AS account_key, accounts.branch_id, accounts.bank_account_id, accounts.employee_id, accounts.expense_type_id, SUM(journal_lines.debit) AS debits, SUM(journal_lines.credit) AS credits')
            ->toBase()
            ->get();

        $legacyReserve = InterestReserves::byBranch(fn () => JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.company_id', $period->company_id)
            ->whereBetween('journal_entries.entry_date', [$period->period_start->toDateString(), $period->period_end->toDateString()])
            ->toBase())['legacy'];

        $lines = [];
        $profit = [];
        foreach ($legacyReserve as $branch => $amount) {
            if (abs($amount) < 0.005) {
                continue;
            }
            $lines[$branch][] = [
                'account' => Account::InterestReserve,
                'branch' => $branch === 0 ? null : $branch,
                'debit' => $amount < 0 ? -$amount : 0,
                'credit' => $amount > 0 ? $amount : 0,
            ];
            $profit[$branch] = round(($profit[$branch] ?? 0) - $amount, 2);
        }
        foreach ($rows as $row) {
            $net = round((float) $row->debits - (float) $row->credits, 2);
            if (abs($net) < 0.005) {
                continue;
            }
            $branch = (int) ($row->branch_id ?? 0);
            $lines[$branch][] = [
                'account' => Account::from($row->account_key),
                'branch' => $row->branch_id,
                'bank' => $row->bank_account_id,
                'employee' => $row->employee_id,
                'expense_type' => $row->expense_type_id,
                'debit' => $net < 0 ? -$net : 0,
                'credit' => $net > 0 ? $net : 0,
            ];
            if ($row->account_key === Account::InsuranceIncome->value) {
                $lines[$branch][] = [
                    'account' => Account::InsuranceReserve,
                    'branch' => $row->branch_id,
                    'debit' => $net > 0 ? $net : 0,
                    'credit' => $net < 0 ? -$net : 0,
                ];

                continue;
            }
            $profit[$branch] = round(($profit[$branch] ?? 0) - $net, 2);
        }

        foreach ($profit as $branch => $amount) {
            $lines[$branch][] = [
                'account' => Account::RetainedProfit,
                'branch' => $branch === 0 ? null : $branch,
                'debit' => $amount < 0 ? -$amount : 0,
                'credit' => $amount > 0 ? $amount : 0,
            ];
        }

        return $lines;
    }
}

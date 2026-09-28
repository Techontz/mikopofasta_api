<?php

namespace App\Services\Reports;

use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\LoanTransaction;
use App\Models\Penalty;
use App\Models\WriteOff;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Queries behind the "Report" sidebar pages.
 *
 * "Loan Amount" on the live reports is the principal + interest (e.g. 260,000 over 4 x 65,000),
 * so rows expose `total_payable` for that column and `amount_approved` only where the page
 * shows the principal explicitly.
 */
class LoanReports
{
    /**
     * Statuses of loans that have been cashed out (have a repayment schedule).
     *
     * @var list<LoanStatus>
     */
    public const WITHDRAWN_STATUSES = [LoanStatus::Active, LoanStatus::Overdue, LoanStatus::Default, LoanStatus::Closed, LoanStatus::WrittenOff];

    /**
     * Cash Transaction: loan deposits and withdrawals in the date range.
     *
     * @return Collection<int, LoanTransaction>
     */
    public function cashTransactions(ReportFilter $filter): Collection
    {
        return LoanTransaction::query()
            ->where('company_id', $filter->company->id)
            ->when($filter->branchId, fn (Builder $query, int $branchId) => $query->where('branch_id', $branchId))
            ->whereNull('reversed_at')
            ->whereBetween('transaction_date', $filter->range())
            ->with('customer')
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Branchwise Loan Summary.
     *
     * Without a date range the live totals equal the whole loan book (they match the Loan Collection
     * totals), so receivable = principal + interest of every withdrawn loan and received = every deposit.
     * With a range, receivable = instalments due in the range, split into principal/interest in the
     * loan's principal:interest proportion (inferred), and received = deposits made in the range.
     *
     * @return Collection<int, array{branch: Branch, receivable: float, receivable_principal: float, receivable_interest: float, received: float, received_principal: float, received_interest: float, pending: float, reserve: float}>
     */
    public function branchSummary(ReportFilter $filter): Collection
    {
        $company = $filter->company;
        $statuses = array_map(fn (LoanStatus $status): string => $status->value, self::WITHDRAWN_STATUSES);

        if ($filter->dated) {
            $receivable = LoanSchedule::query()
                ->join('loans', 'loans.id', '=', 'loan_schedules.loan_id')
                ->where('loans.company_id', $company->id)
                ->whereIn('loans.status', $statuses)
                ->whereBetween('loan_schedules.due_date', $filter->range())
                ->groupBy('loans.branch_id')
                ->selectRaw('loans.branch_id as branch_id')
                ->selectRaw('SUM(loan_schedules.amount) as total')
                ->selectRaw('SUM(CASE WHEN loans.total_payable + loans.insurance > 0 THEN loan_schedules.amount * loans.amount_approved / (loans.total_payable + loans.insurance) ELSE 0 END) as principal')
                ->selectRaw('SUM(CASE WHEN loans.total_payable + loans.insurance > 0 THEN loan_schedules.amount * loans.interest_amount / (loans.total_payable + loans.insurance) ELSE 0 END) as interest')
                ->get()
                ->keyBy('branch_id');
        } else {
            $receivable = Loan::query()
                ->where('company_id', $company->id)
                ->whereIn('status', $statuses)
                ->groupBy('branch_id')
                ->selectRaw('branch_id, SUM(total_payable) as total, SUM(amount_approved) as principal, SUM(interest_amount) as interest')
                ->get()
                ->keyBy('branch_id');
        }

        $received = LoanTransaction::query()
            ->where('company_id', $company->id)
            ->where('type', 'deposit')->whereNull('reversed_at')
            ->when($filter->dated, fn (Builder $query) => $query->whereBetween('transaction_date', $filter->range()))
            ->groupBy('branch_id')
            ->selectRaw('branch_id, SUM(amount) as total, SUM(principal) as principal, SUM(interest) as interest, SUM(reserve) as reserve')
            ->get()
            ->keyBy('branch_id');

        return $company->branches()
            ->when($filter->branchId, fn (Builder $query, int $branchId) => $query->whereKey($branchId))
            ->orderBy('id')
            ->get()
            ->map(function (Branch $branch) use ($receivable, $received): array {
                $due = $receivable->get($branch->id);
                $paid = $received->get($branch->id);
                $total = (float) ($due->total ?? 0);
                $receivedTotal = (float) ($paid->total ?? 0);

                return [
                    'branch' => $branch,
                    'receivable' => $total,
                    'receivable_principal' => (float) ($due->principal ?? 0),
                    'receivable_interest' => (float) ($due->interest ?? 0),
                    'received' => $receivedTotal,
                    'received_principal' => (float) ($paid->principal ?? 0),
                    'received_interest' => (float) ($paid->interest ?? 0),
                    'pending' => max(0.0, $total - $receivedTotal),
                    'reserve' => (float) ($paid->reserve ?? 0),
                ];
            })
            ->values();
    }

    /**
     * "File": loans with collections in the year, with one amount per month that had collections.
     *
     * @return array{loans: Collection<int, Loan>, months: array<int, string>, monthly: array<int, array<int, float>>}
     */
    public function fileReport(Company $company, int $year, ?int $branchId, ?string $status): array
    {
        $statusValue = match ($status) {
            'ACTIVE' => LoanStatus::Active,
            'CLOSED' => LoanStatus::Closed,
            'DEFAULT' => LoanStatus::Default,
            default => null,
        };

        $deposits = LoanTransaction::query()
            ->where('company_id', $company->id)
            ->where('type', 'deposit')->whereNull('reversed_at')
            ->whereNotNull('loan_id')
            ->whereBetween('transaction_date', ["{$year}-01-01", "{$year}-12-31 23:59:59"])
            ->when($branchId, fn (Builder $query, int $id) => $query->where('branch_id', $id))
            ->when($statusValue, fn (Builder $query, LoanStatus $value) => $query->whereHas('loan', fn (Builder $loan) => $loan->where('status', $value->value)))
            ->get(['loan_id', 'amount', 'transaction_date']);

        $monthly = [];
        $monthNumbers = [];
        foreach ($deposits as $deposit) {
            $month = (int) $deposit->transaction_date->format('n');
            $monthNumbers[$month] = true;
            $monthly[$deposit->loan_id][$month] = ($monthly[$deposit->loan_id][$month] ?? 0) + (float) $deposit->amount;
        }
        ksort($monthNumbers);

        $months = [];
        foreach (array_keys($monthNumbers) as $month) {
            $months[$month] = CarbonImmutable::create($year, $month, 1)->format('F');
        }

        $loans = $this->loansWithPayments()
            ->whereKey(array_keys($monthly))
            ->with(['customer', 'branch'])
            ->orderBy('id')
            ->get();

        return ['loans' => $loans, 'months' => $months, 'monthly' => $monthly];
    }

    /**
     * "FILE REPORT NEW LOAN": loans cashed out in the year.
     *
     * @return Collection<int, Loan>
     */
    public function newLoans(Company $company, int $year, ?int $branchId): Collection
    {
        return $this->loansWithPayments()
            ->where('company_id', $company->id)
            ->when($branchId, fn (Builder $query, int $id) => $query->where('branch_id', $id))
            ->whereBetween('withdrawn_at', ["{$year}-01-01", "{$year}-12-31 23:59:59"])
            ->with(['customer', 'branch'])
            ->orderBy('withdrawn_at')
            ->get();
    }

    /**
     * Loan Pending: active loans with instalments that fell due before today and are not fully paid.
     *
     * @return Collection<int, array{loan: Loan, pending: float, date: CarbonImmutable}>
     */
    public function pendingLoans(Company $company, ?int $branchId, CarbonImmutable $today): Collection
    {
        return LoanSchedule::query()
            ->whereHas('loan', fn (Builder $query) => $query->where('company_id', $company->id)
                ->where('status', LoanStatus::Active->value)
                ->when($branchId, fn (Builder $loan, int $id) => $loan->where('branch_id', $id)))
            ->whereDate('due_date', '<', $today->toDateString())
            ->whereColumn('paid_amount', '<', 'amount')
            ->with('loan.customer', 'loan.branch')
            ->orderBy('due_date')
            ->get()
            ->groupBy('loan_id')
            ->map(fn (Collection $schedules): array => [
                'loan' => $schedules->first()->loan,
                'pending' => (float) $schedules->sum(fn (LoanSchedule $schedule): float => (float) $schedule->amount - (float) $schedule->paid_amount),
                'date' => $schedules->first()->due_date->toImmutable(),
            ])
            ->values();
    }

    /**
     * Loan Repayment: the outstanding loan book (active and default loans; inferred).
     *
     * @return Collection<int, Loan>
     */
    public function repayments(Company $company, ?int $branchId): Collection
    {
        return Loan::query()
            ->where('company_id', $company->id)
            ->when($branchId, fn (Builder $query, int $id) => $query->where('branch_id', $id))
            ->status(...LoanStatus::repayable())
            ->with(['customer', 'branch'])
            ->orderBy('withdrawn_at')
            ->get();
    }

    /**
     * Default Loan: loans flagged default, with this month's payments and the remaining debt.
     *
     * @return Collection<int, Loan>
     */
    public function defaultLoans(Company $company, ?int $branchId, CarbonImmutable $today): Collection
    {
        return $this->loansWithPayments()
            ->where('company_id', $company->id)
            ->when($branchId, fn (Builder $query, int $id) => $query->where('branch_id', $id))
            ->status(LoanStatus::Default)
            ->withSum(['transactions as paid_this_month' => fn (Builder $query) => $query->where('type', 'deposit')->whereNull('reversed_at')
                ->whereBetween('transaction_date', [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateTimeString()])], 'amount')
            ->with(['customer', 'branch'])
            ->orderBy('withdrawn_at')
            ->get();
    }

    /**
     * Write-off Loan / Bad Debt Done. "Done" = written-off debt that has been fully recovered (inferred).
     *
     * @return Collection<int, WriteOff>
     */
    public function writeOffs(Company $company, ?int $branchId, bool $recovered = false): Collection
    {
        return WriteOff::query()
            ->whereHas('loan', fn (Builder $query) => $query->where('company_id', $company->id)
                ->when($branchId, fn (Builder $loan, int $id) => $loan->where('branch_id', $id)))
            ->when($recovered, fn (Builder $query) => $query->whereRaw(WriteOff::recoveredSql().' >= write_offs.amount'), fn (Builder $query) => $query->whereRaw(WriteOff::recoveredSql().' < write_offs.amount'))
            ->with(['loan.customer', 'loan.branch', 'employee'])
            ->orderBy('written_off_on')
            ->get();
    }

    /**
     * Loan Collection. Without a status filter the page lists every withdrawn loan.
     * "APROVED" has no separate state in this system: approved loans are DISBURSED awaiting cash-out.
     *
     * @return Collection<int, Loan>
     */
    public function collection(Company $company, ?int $branchId, ?string $status): Collection
    {
        $statuses = match ($status) {
            'PENDING' => [LoanStatus::PendingManagerApproval],
            'APROVED', 'DISBURSED' => [LoanStatus::PendingFinance, LoanStatus::AwaitingDisbursement],
            'ACTIVE' => [LoanStatus::Active],
            'DONE' => [LoanStatus::Closed],
            'DEFALT' => [LoanStatus::Default],
            default => [LoanStatus::Active, LoanStatus::Overdue, LoanStatus::Default, LoanStatus::Closed],
        };

        return $this->loansWithPayments()
            ->where('company_id', $company->id)
            ->when($branchId, fn (Builder $query, int $id) => $query->where('branch_id', $id))
            ->status(...$statuses)
            ->withSum(['penalties as penalty_total' => fn (Builder $query) => $query->where('is_waived', false)], 'amount')
            ->withSum(['penalties as penalty_paid' => fn (Builder $query) => $query->where('is_waived', false)], 'paid_amount')
            ->with(['customer', 'branch', 'employee'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Customer statement for one loan: transactions and penalties in date order with running balances.
     * Balance = cumulative deposits (amount paid to date); Remaining Debt = principal + interest less deposits;
     * Penalty = unpaid penalty to date (column definitions inferred).
     *
     * @return Collection<int, array{date: CarbonImmutable, description: string, deposit: float, withdrawal: float, balance: float, remain: float, penalty: float}>
     */
    public function statement(Loan $loan): Collection
    {
        $events = collect();

        foreach ($loan->transactions()->whereNull('reversed_at')->orderBy('transaction_date')->orderBy('id')->get() as $transaction) {
            $events->push([
                'date' => $transaction->transaction_date->toImmutable(),
                'order' => 0,
                'description' => $transaction->description,
                'deposit' => $transaction->type === 'deposit' ? (float) $transaction->amount : 0.0,
                'withdrawal' => $transaction->type === 'withdrawal' ? (float) $transaction->amount : 0.0,
                'penalty' => 0.0,
                'penalty_paid' => 0.0,
            ]);
        }

        foreach (Penalty::where('loan_id', $loan->id)->where('is_waived', false)->with('payments')->get() as $penalty) {
            $events->push([
                'date' => $penalty->penalty_date->toImmutable(),
                'order' => 1,
                'description' => 'PENALTY',
                'deposit' => 0.0,
                'withdrawal' => 0.0,
                'penalty' => (float) $penalty->amount,
                'penalty_paid' => 0.0,
            ]);

            foreach ($penalty->payments as $payment) {
                $events->push([
                    'date' => $payment->paid_on->toImmutable(),
                    'order' => 2,
                    'description' => 'PENALTY PAYMENT',
                    'deposit' => 0.0,
                    'withdrawal' => 0.0,
                    'penalty' => 0.0,
                    'penalty_paid' => (float) $payment->amount,
                ]);
            }
        }
        $paid = 0.0;
        $penalty = 0.0;
        $total = (float) $loan->total_payable;

        return $events
            ->sortBy([fn (array $a, array $b): int => $a['date'] <=> $b['date'], fn (array $a, array $b): int => $a['order'] <=> $b['order']])
            ->values()
            ->map(function (array $event) use (&$paid, &$penalty, $total): array {
                $paid += $event['deposit'];
                $penalty += $event['penalty'] - $event['penalty_paid'];

                return [
                    'date' => $event['date'],
                    'description' => $event['description'],
                    'deposit' => $event['deposit'],
                    'withdrawal' => $event['withdrawal'],
                    'balance' => $paid,
                    'remain' => max(0, $total - $paid),
                    'penalty' => max(0, $penalty),
                ];
            });
    }

    /**
     * Today Receivable: instalments due in the range.
     *
     * @return Collection<int, LoanSchedule>
     */
    public function receivable(ReportFilter $filter, ?string $paidStatus): Collection
    {
        return LoanSchedule::query()
            ->whereHas('loan', fn (Builder $query) => $query->where('company_id', $filter->company->id)
                ->status(LoanStatus::Active, LoanStatus::Overdue, LoanStatus::Default, LoanStatus::Closed)
                ->when($filter->branchId, fn (Builder $loan, int $id) => $loan->where('branch_id', $id)))
            ->whereBetween('due_date', $filter->range())
            ->when($paidStatus === 'paid', fn (Builder $query) => $query->whereColumn('paid_amount', '>=', 'amount'))
            ->when($paidStatus === 'not paid', fn (Builder $query) => $query->whereColumn('paid_amount', '<', 'amount'))
            ->with('loan.customer', 'loan.branch', 'loan.employee')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Today Received: loan deposits in the range.
     *
     * @return Collection<int, LoanTransaction>
     */
    public function received(ReportFilter $filter): Collection
    {
        return LoanTransaction::query()
            ->where('company_id', $filter->company->id)
            ->where('type', 'deposit')->whereNull('reversed_at')
            ->when($filter->branchId, fn (Builder $query, int $id) => $query->where('branch_id', $id))
            ->whereBetween('transaction_date', $filter->range())
            ->with(['customer', 'branch', 'loan', 'employee'])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Customer Development summary for a marked customer (figures for the most recent loan).
     *
     * @return array{loans: Collection<int, Loan>, latest: ?Loan, salary_advance: float, penalty: float, recovery: float}
     */
    public function development(Customer $customer): array
    {
        $loans = $this->loansWithPayments()
            ->where('customer_id', $customer->id)
            ->with('category')
            ->orderByDesc('id')
            ->get();

        $latest = $loans->first(fn (Loan $loan): bool => $loan->withdrawn_at !== null) ?? $loans->first();

        $salaryAdvance = (float) $customer->salaryAdvances()->where('status', 'active')->withSum('payments', 'amount')->get()
            ->sum(fn ($advance): float => $advance->remaining_amount);

        $penalty = (float) Penalty::where('customer_id', $customer->id)->where('is_waived', false)->get()
            ->sum(fn (Penalty $item): float => max(0, (float) $item->amount - (float) $item->paid_amount));

        $recovery = (float) WriteOff::whereIn('loan_id', $loans->pluck('id'))->selectRaw('COALESCE(SUM('.WriteOff::recoveredSql().'), 0) AS recovered')->value('recovered');

        return ['loans' => $loans, 'latest' => $latest, 'salary_advance' => $salaryAdvance, 'penalty' => $penalty, 'recovery' => $recovery];
    }

    /**
     * Loans with `paid_sum` (all deposits) eager-loaded; use {@see remaining()} for the remaining debt.
     *
     * @return Builder<Loan>
     */
    private function loansWithPayments(): Builder
    {
        return Loan::query()->withSum(['transactions as paid_sum' => fn (Builder $query) => $query->where('type', 'deposit')->whereNull('reversed_at')], 'amount');
    }

    /**
     * Remaining debt of a loan: the authoritative outstanding balance (principal + penalty + interest + insurance).
     */
    public static function remaining(Loan $loan): float
    {
        return app(LoanService::class)->outstanding($loan)['total'];
    }
}

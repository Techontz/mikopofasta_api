<?php

namespace App\Services;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Reports\LoanBalances;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Branch dashboard (Teller, Loan Officer, Branch Manager): the loan book of the employee's branches split into current,
 * arrears and default, the branch petty cash, today's collection and disbursement, the portfolio per customer type, the
 * approval queue and the month's application pipeline. Every figure comes from the records:
 *
 *  - active / arrears / default: repayable loans by status — ACTIVE (on time), OVERDUE (in arrears) and DEFAULT — each with
 *    what is still owed on them (principal + interest + penalty, as the Loan Portfolio report counts it); the total
 *    outstanding is their sum. Its change is the movement of the loan receivable accounts since the end of last month. The
 *    ledger keeps no history of a loan's status, so the three status cards have no month-on-month change;
 *  - petty cash: the branches' PETTY CASH A/C against the end of yesterday;
 *  - today: repayments received (reversals excluded) and loans paid out, against yesterday;
 *  - customer types: customers registered in the branches per customer type, with their active, arrears and default loans;
 *  - approvals: the Credit Department's pipeline queue ({@see CreditDashboard::approvals()});
 *  - pipeline: the loans applied for in the month, by the stage they are at now.
 */
final class BranchDashboard
{
    public function __construct(
        private readonly CreditDashboard $credit,
        private readonly Ledger $ledger,
    ) {}

    /**
     * @param  list<int>|null  $branchIds  null = the whole company
     * @return array<string, mixed>
     */
    public function build(Company $company, Employee $viewer, CarbonImmutable $month, ?array $branchIds, CarbonImmutable $today): array
    {
        $loans = $this->openLoans($company, $branchIds);
        $status = fn (LoanStatus $status): array => [
            'count' => $loans->where('status', $status->value)->count(),
            'amount' => round((float) $loans->where('status', $status->value)->sum('out_total'), 2),
        ];
        $outstanding = round((float) $loans->sum('out_total'), 2);
        $yesterday = $today->subDay();

        $collection = fn (CarbonImmutable $day): Collection => $this->transactions($company, $branchIds, 'deposit', $day);
        $disbursement = fn (CarbonImmutable $day): Collection => $this->transactions($company, $branchIds, 'withdrawal', $day);
        $pettyCash = fn (?CarbonImmutable $until): float => $this->pettyCash($company, $branchIds, $until);

        return [
            'month' => $month->format('Y-m'),
            'month_label' => $month->format('F Y'),
            'cards' => [
                'active' => $status(LoanStatus::Active),
                'arrears' => $status(LoanStatus::Overdue),
                'default' => $status(LoanStatus::Default),
                'outstanding' => [
                    'count' => $loans->count(),
                    'amount' => $outstanding,
                    'change' => $this->change($this->receivable($company, $branchIds, $today), $this->receivable($company, $branchIds, $today->startOfMonth()->subDay())),
                ],
            ],
            'petty_cash' => ['amount' => $pettyCash(null), 'change' => $this->change($pettyCash(null), $pettyCash($yesterday))],
            'today' => [
                'collection' => $this->todayFigure($collection($today), $collection($yesterday)),
                'disbursement' => $this->todayFigure($disbursement($today), $disbursement($yesterday)),
            ],
            'customer_types' => $this->customerTypes($company, $branchIds, $loans),
            'approvals' => $this->credit->approvals($company, $branchIds, $viewer),
            'pipeline' => $this->pipeline($company, $branchIds, $month->startOfMonth(), $month->endOfMonth()),
        ];
    }

    /**
     * Active, overdue and default loans of the branches with what is still owed on each and the customer's type.
     *
     * @param  list<int>|null  $branchIds
     * @return Collection<int, object>
     */
    private function openLoans(Company $company, ?array $branchIds): Collection
    {
        $query = $this->scoped(DB::table('loans'), $company, $branchIds)
            ->leftJoin('customers', 'customers.id', '=', 'loans.customer_id')
            ->whereIn('loans.status', LoanStatus::values(LoanStatus::Active, LoanStatus::Overdue, LoanStatus::Default))
            ->select(['loans.id', 'loans.status', 'customers.customer_category_id']);

        return LoanBalances::join($query)->get();
    }

    /**
     * One row per customer type that has customers or open loans in the branches, and a TOTAL row.
     *
     * @param  list<int>|null  $branchIds
     * @param  Collection<int, object>  $loans
     * @return array{rows: list<array<string, mixed>>, total: array<string, mixed>}
     */
    private function customerTypes(Company $company, ?array $branchIds, Collection $loans): array
    {
        $customers = DB::table('customers')
            ->where('customers.company_id', $company->id)
            ->when($branchIds !== null, fn ($query) => $query->whereIn('customers.branch_id', $branchIds))
            ->whereNull('customers.deleted_at')
            ->groupBy('customers.customer_category_id')
            ->selectRaw('customers.customer_category_id, COUNT(*) as total')
            ->pluck('total', 'customer_category_id');
        $names = DB::table('customer_categories')->where('company_id', $company->id)->orderBy('id')->pluck('name', 'id');
        $byType = $loans->groupBy(fn (object $loan): int => (int) $loan->customer_category_id);

        $figures = function (Collection $items): array {
            $row = [];
            foreach (['active' => LoanStatus::Active, 'arrears' => LoanStatus::Overdue, 'default' => LoanStatus::Default] as $key => $status) {
                $row[$key.'_count'] = $items->where('status', $status->value)->count();
                $row[$key.'_amount'] = round((float) $items->where('status', $status->value)->sum('out_total'), 2);
            }
            $row['outstanding'] = round((float) $items->sum('out_total'), 2);

            return $row;
        };

        $rows = collect($names->keys())->merge($customers->keys())->merge($byType->keys())
            ->map(fn ($id): int => (int) $id)->unique()
            ->filter(fn (int $id): bool => (int) ($customers[$id] ?? 0) > 0 || $byType->has($id))
            ->map(fn (int $id): array => [
                'id' => $id,
                'label' => $names[$id] ?? 'Not set',
                'customers' => (int) ($customers[$id] ?? 0),
            ] + $figures($byType->get($id, collect())))
            ->sortBy(fn (array $row): int => $row['id'] === 0 ? PHP_INT_MAX : $row['id'])
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'total' => ['customers' => array_sum(array_column($rows, 'customers'))] + $figures($loans),
        ];
    }

    /**
     * The month's applications by the stage they are at now; each stage with its number of loans and amount (the approved
     * amount once set, else the amount applied for).
     *
     * @param  list<int>|null  $branchIds
     * @return list<array{key: string, label: string, count: int, amount: float}>
     */
    private function pipeline(Company $company, ?array $branchIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $loans = $this->scoped(DB::table('loans'), $company, $branchIds)
            ->whereBetween('loans.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->get(['loans.status', 'loans.approved_at', DB::raw('COALESCE(loans.amount_approved, loans.amount_applied) as amount')]);

        $stage = fn (string $key, string $label, Collection $items): array => [
            'key' => $key,
            'label' => $label,
            'count' => $items->count(),
            'amount' => round((float) $items->sum('amount'), 2),
        ];
        $in = fn (LoanStatus ...$statuses): Collection => $loans->whereIn('status', LoanStatus::values(...$statuses));
        $ended = LoanStatus::values(LoanStatus::Rejected, LoanStatus::Cancelled);

        return [
            $stage('applications', 'New Applications', $loans),
            $stage('manager', 'Under Review', $in(LoanStatus::PendingManagerApproval, LoanStatus::Returned)),
            $stage('credit', 'Credit Assessment', $in(LoanStatus::MandatePendingOtp, LoanStatus::MandateFailed, LoanStatus::PendingCreditReview)),
            $stage('finance', 'Pending Approval', $in(LoanStatus::PendingFinance)),
            // Approved: passed the branch manager and not rejected or cancelled since, as the Credit dashboard counts it.
            $stage('approved', 'Approved', $loans->filter(fn (object $loan): bool => $loan->approved_at !== null && ! in_array($loan->status, $ended, true))),
            $stage('ready', 'Ready for Disbursement', $in(LoanStatus::AwaitingDisbursement)),
            $stage('rejected', 'Rejected', $in(LoanStatus::Rejected)),
        ];
    }

    /**
     * Loan repayments ('deposit') or pay-outs ('withdrawal') of one day, reversals excluded.
     *
     * @param  list<int>|null  $branchIds
     * @return Collection<int, object>
     */
    private function transactions(Company $company, ?array $branchIds, string $type, CarbonImmutable $day): Collection
    {
        return DB::table('loan_transactions')
            ->where('company_id', $company->id)
            ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->where('type', $type)
            ->whereNull('reversed_at')
            ->whereDate('transaction_date', $day->toDateString())
            ->get(['loan_id', 'amount']);
    }

    /**
     * @param  Collection<int, object>  $today
     * @param  Collection<int, object>  $yesterday
     * @return array{amount: float, loans: int, change: float|null}
     */
    private function todayFigure(Collection $today, Collection $yesterday): array
    {
        $amount = round((float) $today->sum('amount'), 2);

        return [
            'amount' => $amount,
            'loans' => $today->pluck('loan_id')->filter()->unique()->count(),
            'change' => $this->change($amount, round((float) $yesterday->sum('amount'), 2)),
        ];
    }

    /**
     * PETTY CASH A/C of the branches (every branch for a company-wide employee), now or at the end of a day.
     *
     * @param  list<int>|null  $branchIds
     */
    private function pettyCash(Company $company, ?array $branchIds, ?CarbonImmutable $until): float
    {
        $ids = $branchIds ?? Branch::where('company_id', $company->id)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return round(array_sum(array_map(fn (int $branchId): float => $this->ledger->balance($company, Account::PettyCash, $branchId, until: $until), $ids)), 2) + 0.0;
    }

    /**
     * Principal still owed on the branches' loans at the end of a day: the loan receivable accounts (current, arrears, default).
     *
     * @param  list<int>|null  $branchIds
     */
    private function receivable(Company $company, ?array $branchIds, CarbonImmutable $until): float
    {
        $total = 0.0;
        foreach ([Account::LoanReceivable, Account::LoanArrears, Account::LoanDefault] as $account) {
            if ($branchIds === null) {
                $total += $this->ledger->balance($company, $account, until: $until, allBranches: true);

                continue;
            }
            foreach ($branchIds as $branchId) {
                $total += $this->ledger->balance($company, $account, $branchId, until: $until);
            }
        }

        return round($total, 2);
    }

    /**
     * @param  list<int>|null  $branchIds
     */
    private function scoped(Builder $query, Company $company, ?array $branchIds): Builder
    {
        return $query->where('loans.company_id', $company->id)
            ->when($branchIds !== null, fn ($inner) => $inner->whereIn('loans.branch_id', $branchIds));
    }

    private function change(float $current, float $previous): ?float
    {
        return abs($previous) < 0.005 ? null : round(($current - $previous) / abs($previous) * 100, 1);
    }
}

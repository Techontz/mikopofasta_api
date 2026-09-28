<?php

namespace App\Services\Reports\Financial;

use App\Enums\Account;
use App\Enums\PaymentStatus;
use App\Models\ExpenseRequest;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\TellerDeposit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Expense (tagging), Suspense and Reversal reports (OVERVIEW ALL REPORT §3C, §4B, §8B/C and the GAP reports).
 */
class ControlReports
{
    public const AGING_BUCKETS = ['0-7 days' => [0, 7], '8-30 days' => [8, 30], '31-60 days' => [31, 60], '61-90 days' => [61, 90], '90+ days' => [91, PHP_INT_MAX]];

    /**
     * EXPENSE TAGGING REPORT: all accepted expenses, branch vs HQ tag, category, paid-from account,
     * mis-tag detection, HQ-paid but branch-tagged expenses and month-to-month comparison.
     *
     * Mis-tag rules (Documents: branch expenses are paid from the branch Interest A/C, HQ expenses from HQ
     * accounts and never from branch interest):
     *  - branch expense without a branch; branch expense not paid from INTEREST A/C;
     *  - HQ/bank expense paid from a branch INTEREST A/C;
     *  - expense type registered for another scope;
     *  - accepted but not posted to the ledger.
     *
     * Reversed expenses (status "reversed", or their ledger entry reversed) stay listed with `reversed` = true and their
     * reversal details, but are excluded from every total, grouping and the mis-tag counts.
     *
     * @return array<string, mixed>
     */
    public function expenses(FinancialScope $scope): array
    {
        $query = ExpenseRequest::query()
            ->where('company_id', $scope->companyId)
            ->whereIn('status', ['accepted', 'reversed'])
            ->whereRaw('DATE(COALESCE(approved_at, request_date)) BETWEEN ? AND ?', [$scope->from->toDateString(), $scope->to->toDateString()])
            ->with(['branch:id,name', 'expenseType:id,name,scope', 'employee:id,first_name,middle_name,last_name', 'approver:id,first_name,middle_name,last_name', 'journalEntry.reversal'])
            ->orderByRaw('COALESCE(approved_at, request_date)');
        $this->scopeBranch($query, $scope, 'scope');

        $rows = $query->get()->map(function (ExpenseRequest $expense): array {
            $flags = $this->expenseFlags($expense);
            $hqPaidBranchTagged = $expense->scope !== 'branch' && $expense->branch_id !== null;
            $paidFrom = Account::tryFrom((string) $expense->paid_from_account);

            return [
                'id' => $expense->id,
                'date' => ($expense->approved_at ?? $expense->request_date)?->toDateString(),
                'expense_type' => $expense->expenseType?->name,
                'scope' => strtoupper($expense->scope),
                'tag' => $expense->scope === 'branch' ? 'BRANCH' : ($hqPaidBranchTagged ? 'HQ-PAID / BRANCH-TAGGED' : 'HQ'),
                'branch' => $expense->branch?->name,
                'paid_from' => $paidFrom?->label() ?? $expense->paid_from_account,
                'amount' => (float) $expense->amount,
                'description' => $expense->description,
                'requested_by' => $expense->employee?->full_name,
                'approved_by' => $expense->approver?->full_name,
                'journal_reference' => $expense->journalEntry?->reference,
                'mis_tagged' => $flags !== [],
                'flags' => $flags,
                'reversed' => $expense->isReversed() || $expense->reversed_at !== null || $expense->journalEntry?->reversal !== null,
                'reversed_at' => ($expense->reversed_at ?? $expense->journalEntry?->reversal?->created_at)?->format('Y-m-d H:i:s'),
                'reversal_reason' => $expense->reversal_reason ?? $expense->journalEntry?->reversal?->reversal_reason,
            ];
        });
        $allRows = $rows;
        $rows = $rows->where('reversed', false)->values();

        $byCategory = $rows->groupBy('expense_type')->map(fn (Collection $group, string $name): array => ['label' => $name ?: 'OTHER', 'count' => $group->count(), 'amount' => round($group->sum('amount'), 2)])->sortByDesc('amount')->values()->all();
        $byBranch = $rows->groupBy(fn (array $row): string => $row['branch'] ?? 'HQ')->map(fn (Collection $group, string $name): array => [
            'label' => $name,
            'branch_tagged' => round($group->where('tag', 'BRANCH')->sum('amount'), 2),
            'hq_paid_branch_tagged' => round($group->where('tag', 'HQ-PAID / BRANCH-TAGGED')->sum('amount'), 2),
            'hq' => round($group->where('tag', 'HQ')->sum('amount'), 2),
            'amount' => round($group->sum('amount'), 2),
        ])->values()->all();

        $months = [];
        for ($month = $scope->from->startOfMonth(); $month->lte($scope->to); $month = $month->addMonth()) {
            $monthRows = $rows->filter(fn (array $row): bool => str_starts_with((string) $row['date'], $month->format('Y-m')));
            $months[] = [
                'month' => $month->format('Y-m'),
                'branch' => round($monthRows->where('scope', 'BRANCH')->sum('amount'), 2),
                'hq' => round($monthRows->where('scope', '!=', 'BRANCH')->sum('amount'), 2),
            ];
        }
        foreach ($months as $index => $month) {
            $previous = $months[$index - 1]['hq'] ?? null;
            $months[$index]['hq_change_percent'] = $previous ? round(($month['hq'] - $previous) / $previous * 100, 2) : null;
        }

        return [
            'rows' => $allRows->values()->all(),
            'reversed_count' => $allRows->where('reversed', true)->count(),
            'reversed_total' => round($allRows->where('reversed', true)->sum('amount'), 2),
            'by_category' => $byCategory,
            'by_branch' => $byBranch,
            'months' => $months,
            'total' => round($rows->sum('amount'), 2),
            'branch_total' => round($rows->where('tag', 'BRANCH')->sum('amount'), 2),
            'hq_total' => round($rows->where('tag', 'HQ')->sum('amount'), 2),
            'hq_paid_branch_tagged_total' => round($rows->where('tag', 'HQ-PAID / BRANCH-TAGGED')->sum('amount'), 2),
            'mis_tagged_count' => $rows->where('mis_tagged', true)->count(),
            'mis_tagged_total' => round($rows->where('mis_tagged', true)->sum('amount'), 2),
        ];
    }

    /**
     * SUSPENSE ACCOUNT REPORT: unmatched payments, pending allocations (teller cash not yet verified),
     * deposits pending reconciliation, aging (how long unresolved) and the ledger suspense balance.
     *
     * Inferred: statuses are the current ones; age is counted up to the as-of date.
     *
     * @return array<string, mixed>
     */
    public function suspense(FinancialScope $scope): array
    {
        $asOf = $scope->to;
        $age = fn (?CarbonImmutable $date): int => $date ? max(0, (int) $date->diffInDays($asOf)) : 0;
        $bucket = function (int $days): string {
            foreach (self::AGING_BUCKETS as $label => [$min, $max]) {
                if ($days >= $min && $days <= $max) {
                    return $label;
                }
            }

            return '90+ days';
        };

        $payments = Payment::query()
            ->where('company_id', $scope->companyId)
            ->whereDate('paid_on', '<=', $asOf->toDateString())
            ->whereIn('status', PaymentStatus::values(PaymentStatus::Unallocated, PaymentStatus::Flagged, PaymentStatus::PendingVerification, PaymentStatus::Deposited))
            ->with(['branch:id,name', 'customer:id,first_name,middle_name,last_name', 'employee:id,first_name,middle_name,last_name'])
            ->orderBy('paid_on');
        $this->scopeBranch($payments, $scope);

        $rows = $payments->get()->map(function (Payment $payment) use ($age, $bucket): array {
            $days = $age(CarbonImmutable::parse($payment->paid_on->toDateString()));
            $pending = in_array($payment->status, [PaymentStatus::PendingVerification, PaymentStatus::Deposited], true);

            return [
                'id' => $payment->id,
                'type' => $pending ? 'PENDING ALLOCATION' : 'UNMATCHED',
                'date' => $payment->paid_on->toDateString(),
                'receipt_number' => $payment->receipt_number,
                'reference' => $payment->transaction_id ?? $payment->reference,
                'channel' => strtoupper((string) $payment->channel),
                'phone' => $payment->phone,
                'branch' => $payment->branch?->name ?? 'HQ',
                'customer' => $payment->customer?->full_name,
                'amount' => (float) $payment->amount,
                'allocated' => (float) $payment->allocated_amount,
                'unallocated' => $pending ? (float) $payment->amount : $payment->unallocated_amount,
                'status' => $payment->status->label(),
                'status_badge' => $payment->status->badge(),
                'reason' => $payment->flag_reason,
                'age_days' => $days,
                'bucket' => $bucket($days),
            ];
        })->filter(fn (array $row): bool => $row['unallocated'] > 0)->values();

        $deposits = TellerDeposit::query()
            ->where('company_id', $scope->companyId)
            ->whereDate('deposit_date', '<=', $asOf->toDateString())
            ->whereIn('status', [TellerDeposit::STATUS_PENDING, TellerDeposit::STATUS_VERIFIED, TellerDeposit::STATUS_MISMATCH])
            ->with(['branch:id,name', 'employee:id,first_name,middle_name,last_name'])
            ->orderBy('deposit_date');
        $this->scopeBranch($deposits, $scope);
        $depositRows = $deposits->get()->map(function (TellerDeposit $deposit) use ($age, $bucket): array {
            $days = $age(CarbonImmutable::parse($deposit->deposit_date->toDateString()));

            return [
                'id' => $deposit->id,
                'date' => $deposit->deposit_date->toDateString(),
                'slip_number' => $deposit->slip_number,
                'branch' => $deposit->branch?->name,
                'teller' => $deposit->employee?->full_name,
                'amount' => (float) $deposit->amount,
                'statement_amount' => $deposit->statement_amount !== null ? (float) $deposit->statement_amount : null,
                'status' => strtoupper($deposit->status),
                'age_days' => $days,
                'bucket' => $bucket($days),
            ];
        });

        $aging = [];
        foreach (array_keys(self::AGING_BUCKETS) as $label) {
            $group = $rows->where('bucket', $label);
            $aging[] = ['bucket' => $label, 'count' => $group->count(), 'amount' => round($group->sum('unallocated'), 2)];
        }

        $ledgerQuery = DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.company_id', $scope->companyId)
            ->where('accounts.key', Account::Suspense->value)
            ->whereDate('journal_entries.entry_date', '<=', $asOf->toDateString());
        // One central pending account at HQ (specification §11), so a branch's share is found through the branch the
        // entry was recorded for. Lines posted before centralisation still carry their branch on the account itself.
        if (! $scope->isCompanyWide()) {
            $attribution = 'COALESCE(accounts.branch_id, journal_entries.branch_id)';
            $ledgerQuery->where(function ($inner) use ($scope, $attribution): void {
                $scope->branchIds === null
                    ? $inner->whereRaw("{$attribution} IS NOT NULL")
                    : $inner->whereIn(DB::raw($attribution), $scope->branchIds === [] ? [0] : $scope->branchIds);
                if ($scope->includeHq) {
                    $inner->orWhereRaw("{$attribution} IS NULL");
                }
            });
        }
        $ledgerBalance = round((float) $ledgerQuery->sum(DB::raw('journal_lines.credit - journal_lines.debit')), 2);
        $open = round($rows->sum('unallocated'), 2);

        // Branch receipts Finance has not approved yet: no journal exists, so they are not in the ledger balance, but
        // they are pending/unverified money all the same (§11) and HQ must see them beside the rest.
        $awaiting = Payment::query()
            ->where('company_id', $scope->companyId)
            ->whereDate('paid_on', '<=', $asOf->toDateString())
            ->where('status', PaymentStatus::PendingApproval->value);
        $this->scopeBranch($awaiting, $scope);
        $awaitingTotal = round((float) $awaiting->sum('amount'), 2);

        $byBranch = $rows->where('type', 'PENDING ALLOCATION')->groupBy('branch')->map(fn (Collection $group): float => round($group->sum('unallocated'), 2));
        foreach ((clone $awaiting)->with('branch:id,name')->get() as $receipt) {
            $name = $receipt->branch?->name ?? 'HQ';
            $byBranch[$name] = round(($byBranch[$name] ?? 0) + (float) $receipt->amount, 2);
        }

        return [
            'as_of' => $asOf->toDateString(),
            'rows' => $rows->all(),
            'deposits' => $depositRows->all(),
            'aging' => $aging,
            'unmatched_total' => round($rows->where('type', 'UNMATCHED')->sum('unallocated'), 2),
            'pending_allocation_total' => round($rows->where('type', 'PENDING ALLOCATION')->sum('unallocated'), 2),
            'deposits_total' => round($depositRows->sum('amount'), 2),
            'open_total' => $open,
            'awaiting_approval_total' => $awaitingTotal,
            // §11: money a branch says it received that Finance has not verified — never income or repayment yet.
            'unverified_total' => round($rows->where('type', 'PENDING ALLOCATION')->sum('unallocated') + $awaitingTotal, 2),
            'unverified_by_branch' => $byBranch->sortKeys()->map(fn (float $amount, string $branch): array => ['branch' => $branch, 'amount' => $amount])->values()->all(),
            'ledger_balance' => $ledgerBalance,
            'difference' => round($ledgerBalance - $open, 2),
        ];
    }

    /**
     * REVERSAL REPORT: all reversed transactions with reasons.
     *
     * @return array<string, mixed>
     */
    public function reversals(FinancialScope $scope): array
    {
        $query = JournalEntry::query()
            ->where('company_id', $scope->companyId)
            ->whereNotNull('reversal_of_id')
            ->whereBetween('entry_date', [$scope->from->toDateString(), $scope->to->toDateString()])
            ->with(['reversalOf:id,reference,entry_date,description,employee_id', 'reversalOf.employee:id,first_name,middle_name,last_name', 'branch:id,name', 'employee:id,first_name,middle_name,last_name'])
            ->withSum('lines as amount', 'debit')
            ->orderBy('entry_date')
            ->orderBy('id');
        $this->scopeBranch($query, $scope);

        $rows = $query->get()->map(fn (JournalEntry $entry): array => [
            'id' => $entry->id,
            'date' => $entry->entry_date->toDateString(),
            'reference' => $entry->reference,
            'original_reference' => $entry->reversalOf?->reference,
            'original_date' => $entry->reversalOf?->entry_date?->toDateString(),
            'description' => $entry->reversalOf?->description ?? $entry->description,
            'source' => $entry->source_type ? class_basename($entry->source_type) : 'MANUAL',
            'amount' => round((float) $entry->amount, 2),
            'branch' => $entry->branch?->name ?? 'HQ',
            'posted_by' => $entry->reversalOf?->employee?->full_name,
            'reversed_by' => $entry->employee?->full_name,
            'reason' => $entry->reversal_reason,
        ]);

        return [
            'rows' => $rows->all(),
            'count' => $rows->count(),
            'total' => round($rows->sum('amount'), 2),
            'by_source' => $rows->groupBy('source')->map(fn (Collection $group, string $source): array => ['label' => $source, 'count' => $group->count(), 'amount' => round($group->sum('amount'), 2)])->values()->all(),
        ];
    }

    /**
     * @return list<string>
     */
    private function expenseFlags(ExpenseRequest $expense): array
    {
        $flags = [];
        $pettyCash = $expense->paid_from_account === Account::PettyCash->value;

        if ($expense->scope === 'branch' && $expense->branch_id === null) {
            $flags[] = 'Branch expense without a branch';
        }
        if ($expense->scope === 'branch' && $expense->paid_from_account !== null && ! $pettyCash) {
            $flags[] = 'Branch expense not paid from PETTY CASH A/C';
        }
        if ($expense->scope !== 'branch' && $pettyCash) {
            $flags[] = 'HQ expense paid from branch PETTY CASH A/C';
        }
        if ($expense->expenseType !== null && $expense->expenseType->scope !== $expense->scope) {
            $flags[] = 'Expense type registered for '.strtoupper((string) $expense->expenseType->scope);
        }
        if ($expense->journal_entry_id === null) {
            $flags[] = 'Not posted to ledger';
        }

        return $flags;
    }

    /**
     * Branch filter on an Eloquent query with a nullable branch_id (null = HQ).
     *
     * @param  Builder<Model>  $query
     */
    private function scopeBranch(Builder $query, FinancialScope $scope, ?string $scopeColumn = null): void
    {
        if ($scope->isCompanyWide()) {
            return;
        }
        $model = $query->getModel();
        $column = $model->qualifyColumn('branch_id');
        $scopeQualified = $scopeColumn !== null ? $model->qualifyColumn($scopeColumn) : null;

        $query->where(function (Builder $inner) use ($scope, $column, $scopeQualified): void {
            $scope->branchIds === [] ? $inner->whereRaw('1 = 0') : $inner->whereIn($column, $scope->branchIds ?? [0]);
            if ($scope->includeHq) {
                $scopeQualified !== null ? $inner->orWhere($scopeQualified, '!=', 'branch') : $inner->orWhereNull($column);
            }
        });
    }
}

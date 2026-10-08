<?php

namespace App\Services\Customers;

use App\Enums\LoanStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\Penalty;
use App\Models\SalaryAdvance;
use App\Services\LoanService;
use App\Services\Reports\InstalmentBehaviour;
use App\Services\Reports\LoanBalances;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Customer Profile's Credit History tab: what the customer has done throughout their borrowing history, read from the
 * records that already exist. It is a reporting layer only — nothing here writes, and no figure is added on top of another:
 *
 *  - balances are the ones the rest of the profile shows: per loan {@see LoanBalances} (paid / remain, Principal → Penalty →
 *    Interest → Insurance split stored at posting), and the debt totals come from {@see CustomerDebt} unchanged, so Total
 *    Outstanding here is the Debt Profile total, never a second sum of it;
 *  - instalment behaviour comes from {@see InstalmentBehaviour}: the schedule stores only the amount paid, so the payment
 *    date of each instalment is the date of the repayment that completed it when the loan's deposits are replayed in date
 *    order. On time = completed on or before its due date; late = completed after it; missed = due and still not fully
 *    paid today. An arrears event is any instalment that went past its due date unpaid (late + missed); arrears are not a
 *    default;
 *  - a default is a loan that reached DEFAULT (its end date passed with a balance — {@see LoanService::applyPenaltiesAndDefaults()}),
 *    or was written off. The default date is when the audit trail first recorded status = default; outstanding at default is
 *    the principal + interest + insurance still unpaid on that day (penalties left out);
 *  - Loans, penalties and salary advances carried over from the old system (`is_legacy_opening`, Source = Legacy System) are
 *    listed with the rest, but the old system printed one Loan Amount (principal + interest) and never split it, so their
 *    principal, interest and profit are N/A (null) and never enter a profit total; they have no schedule here, so they take
 *    no part in instalment performance either. What that system had already collected (`opening_paid_principal`,
 *    `opening_paid`) counts as paid, exactly like the Debt Profile opens them at the printed Remain Amount;
 *  - salary advances (the customer product, not staff advances) are reported apart and never mixed into loan figures.
 *
 * Every query is bounded to the one customer; nothing is loaded per loan.
 */
final class CustomerCreditHistory
{
    public const SOURCE_LEGACY = 'legacy';

    public const SOURCE_CURRENT = 'current';

    public function __construct(
        private readonly CustomerDebt $debts,
        private readonly InstalmentBehaviour $behaviour,
    ) {}

    /**
     * @return array{summary: array<string, mixed>, loans: list<array<string, mixed>>, repayment: array<string, mixed>, arrears: list<array<string, mixed>>, defaults: array{times_defaulted: int, rows: list<array<string, mixed>>}, penalties: array{totals: array<string, mixed>, rows: list<array<string, mixed>>}, profit: array<string, mixed>, salary_advances: array{totals: array<string, mixed>, rows: list<array<string, mixed>>}, performance: list<string>}
     */
    public function for(Customer $customer, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $debt = $this->debts->summary($customer);

        $loans = LoanBalances::join(Loan::query()->where('loans.customer_id', $customer->id))
            ->with(['branch:id,name', 'category:id,name', 'writeOff:id,loan_id,written_off_on'])
            ->orderBy('loans.id')
            ->get();
        $disbursed = $loans->filter(fn (Loan $loan): bool => in_array($loan->status, LoanStatus::disbursed(), true))->values();

        $instalments = $this->behaviour->instalments($disbursed->where('is_legacy_opening', false)->modelKeys(), $today);
        $loanRows = $loans->map(fn (Loan $loan): array => $this->loanRow($loan, $instalments))->values();
        $repayable = $disbursed->filter(fn (Loan $loan): bool => in_array($loan->status, LoanStatus::repayable(), true));
        $defaults = $this->defaults($disbursed);
        $penalties = $this->penalties($customer, $loans, $debt);
        $profit = $this->profit($disbursed);
        $advances = $this->salaryAdvances($customer, $debt);
        $repayment = $this->repayment($disbursed, $repayable, $instalments, count($defaults));

        $summary = [
            'total_loans' => $disbursed->count(),
            'completed_loans' => $disbursed->where('status', LoanStatus::Closed)->count(),
            'active_loans' => $disbursed->whereIn('status', [LoanStatus::Active, LoanStatus::Overdue])->count(),
            'defaulted_loans' => $disbursed->whereIn('status', [LoanStatus::Default, LoanStatus::WrittenOff])->count(),
            'applications_not_disbursed' => $loans->count() - $disbursed->count(),
            'total_borrowed' => round((float) $disbursed->sum(fn (Loan $loan): float => (float) $loan->amount_approved), 2),
            'old_system_borrowed' => round((float) $disbursed->where('is_legacy_opening', true)->sum(fn (Loan $loan): float => (float) $loan->amount_approved), 2),
            'total_repaid' => round((float) $disbursed->sum(fn (Loan $loan): float => $this->paid($loan)), 2),
            'old_system_repaid' => round((float) $disbursed->sum(fn (Loan $loan): float => (float) $loan->opening_paid_principal), 2),
            'total_outstanding' => $debt['total'],
            'outstanding' => [
                'loans' => $debt['loan_total'],
                'penalty' => $debt['penalty'],
                'salary_advance' => $debt['salary_advance'],
                'old_system' => $debt['old_system']['total'],
            ],
            'total_penalties_charged' => $penalties['totals']['charged'],
            'total_interest' => $profit['total'],
            'interest_collected' => $profit['collected'],
            'credit_status' => $this->creditStatus($disbursed, $loanRows, $debt),
        ];

        return [
            'summary' => $summary,
            'loans' => $loanRows->sortByDesc('id')->values()->all(),
            'repayment' => $repayment,
            'arrears' => $this->arrears($instalments, $loans),
            'defaults' => ['times_defaulted' => count($defaults), 'rows' => $defaults],
            'penalties' => $penalties,
            'profit' => $profit,
            'salary_advances' => $advances,
            'performance' => $this->performance($customer, $summary, $repayment, $penalties['totals'], $advances['totals']),
        ];
    }

    /**
     * Everything repaid on a loan: the repayments recorded here (reversals excluded, penalty part included) plus what the
     * old system had already collected on a loan carried over from it.
     */
    private function paid(Loan $loan): float
    {
        return round((float) $loan->paid_total + (float) $loan->opening_paid_principal, 2);
    }

    /**
     * One Loan History row. A loan that was never disbursed has nothing paid or remaining (null); an old-system loan shows its
     * printed Loan Amount as Principal + Interest and N/A for the split it never had.
     *
     * @param  Collection<int, array<string, mixed>>  $instalments
     * @return array<string, mixed>
     */
    private function loanRow(Loan $loan, Collection $instalments): array
    {
        $legacy = (bool) $loan->is_legacy_opening;
        $disbursed = in_array($loan->status, LoanStatus::disbursed(), true);
        $paid = $disbursed ? $this->paid($loan) : null;
        $remain = $disbursed ? (float) $loan->out_total : null;
        $inArrears = in_array($loan->status, LoanStatus::repayable(), true)
            && ($loan->status === LoanStatus::Overdue || (int) $loan->days_past_due > 0
                || $instalments->contains(fn (array $row): bool => $row['loan_id'] === $loan->id && $row['is_due'] && ! $row['is_paid']));

        return [
            'id' => $loan->id,
            'loan_number' => $loan->loan_number,
            'reference_number' => $loan->reference_number,
            'product' => $loan->category?->name,
            'loan_date' => ($legacy ? $loan->withdrawn_at?->toDateString() : null) ?? $loan->created_at?->toDateString(),
            'loan_amount' => $legacy ? (float) $loan->amount_approved : (float) $loan->amount_applied,
            'principal' => $legacy ? null : (float) $loan->amount_approved,
            'interest' => $legacy ? null : (float) $loan->interest_amount,
            'principal_interest' => $legacy ? (float) $loan->amount_approved : round((float) $loan->amount_approved + (float) $loan->interest_amount, 2),
            'paid' => $paid,
            'remain' => $remain,
            'status' => $loan->status->value,
            'status_label' => $loan->status->label(),
            'status_badge' => $loan->status->badge(),
            'status_group' => $this->statusGroup($loan->status),
            'in_arrears' => $inArrears,
            'payment_status' => match (true) {
                ! $disbursed => 'not_disbursed',
                $remain <= 0.004 => 'paid',
                $paid > 0.004 => 'partially_paid',
                default => 'unpaid',
            },
            'withdrawn_at' => $loan->withdrawn_at?->toDateString(),
            'end_date' => $loan->end_date?->toDateString(),
            'closed_at' => $loan->closed_at?->toDateString(),
            'source' => $legacy ? self::SOURCE_LEGACY : self::SOURCE_CURRENT,
            'branch_id' => $loan->branch_id,
            'branch_name' => $loan->branch?->name,
        ];
    }

    /**
     * The Loan Status filter's groups: completed (DONE), active (ACTIVE, OVERDUE), default (DEFAULT, WRITE-OFF) and the
     * applications that never reached the customer (pipeline, rejected, cancelled).
     */
    private function statusGroup(LoanStatus $status): string
    {
        return match ($status) {
            LoanStatus::Closed => 'completed',
            LoanStatus::Active, LoanStatus::Overdue => 'active',
            LoanStatus::Default, LoanStatus::WrittenOff => 'default',
            default => 'not_disbursed',
        };
    }

    /**
     * Instalment performance of this system's disbursed loans (old-system loans carry no schedule here and are only counted
     * in `loans_without_schedule`). The arrears amount is what is still unpaid on instalments already due, on loans that
     * are still repayable — a closed or written-off loan no longer carries arrears. The on-time rate is on time ÷ instalments
     * already due; null (N/A) while nothing has fallen due.
     *
     * @param  Collection<int, Loan>  $disbursed
     * @param  Collection<int, Loan>  $repayable
     * @param  Collection<int, array<string, mixed>>  $instalments
     * @return array<string, mixed>
     */
    private function repayment(Collection $disbursed, Collection $repayable, Collection $instalments, int $timesDefaulted): array
    {
        $due = $instalments->where('is_due', true);
        $onTime = $due->filter(fn (array $row): bool => $row['is_paid'] && $row['delay_days'] <= 0)->count();
        $late = $due->filter(fn (array $row): bool => $row['is_paid'] && $row['delay_days'] > 0)->count();
        $missed = $due->filter(fn (array $row): bool => ! $row['is_paid'])->count();
        $repayableIds = $repayable->modelKeys();
        $scheduled = $instalments->pluck('loan_id')->unique();

        return [
            'total_instalments' => $instalments->count(),
            'paid_instalments' => $instalments->where('is_paid', true)->count(),
            'due_instalments' => $due->count(),
            'on_time' => $onTime,
            'late' => $late,
            'missed' => $missed,
            'arrears_events' => $late + $missed,
            'arrears_amount' => round((float) $due->filter(fn (array $row): bool => ! $row['is_paid'] && in_array($row['loan_id'], $repayableIds, true))
                ->sum(fn (array $row): float => max(0.0, $row['amount'] - $row['paid_amount'])), 2),
            'max_days_overdue' => $due->isEmpty() ? null : (int) max(0, (int) $due->max('delay_days')),
            'times_defaulted' => $timesDefaulted,
            'on_time_rate' => $due->isEmpty() ? null : round($onTime / $due->count() * 100, 1),
            'loans_without_schedule' => $disbursed->reject(fn (Loan $loan): bool => $scheduled->contains($loan->id))->count(),
        ];
    }

    /**
     * Arrears History: every instalment that went past its due date unpaid — paid late (its arrears since cleared) or still
     * unpaid (partially paid or missed). Arrears is what is unpaid on it today.
     *
     * @param  Collection<int, array<string, mixed>>  $instalments
     * @param  Collection<int, Loan>  $loans
     * @return list<array<string, mixed>>
     */
    private function arrears(Collection $instalments, Collection $loans): array
    {
        $byId = $loans->keyBy('id');

        return $instalments
            ->filter(fn (array $row): bool => $row['is_due'] && (int) $row['delay_days'] > 0)
            ->sortByDesc('due_date')
            ->map(function (array $row) use ($byId): array {
                /** @var Loan $loan */
                $loan = $byId->get($row['loan_id']);
                $status = match (true) {
                    $row['is_paid'] => 'paid_late',
                    $row['paid_amount'] > 0.004 => 'partially_paid',
                    default => 'missed',
                };

                return [
                    'id' => $row['id'],
                    'due_date' => $row['due_date'],
                    'loan_id' => $loan->id,
                    'loan_number' => $loan->loan_number,
                    'expected' => $row['amount'],
                    'paid' => $row['paid_amount'],
                    'arrears' => round(max(0.0, $row['amount'] - $row['paid_amount']), 2),
                    'days_overdue' => (int) $row['delay_days'],
                    'paid_date' => $row['paid_date'],
                    'status' => $status,
                    'status_label' => ['paid_late' => 'Paid late', 'partially_paid' => 'Partially paid', 'missed' => 'Missed'][$status],
                    'loan_status_label' => $loan->status->label(),
                    'source' => self::SOURCE_CURRENT,
                    'branch_id' => $loan->branch_id,
                    'branch_name' => $loan->branch?->name,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Default History: loans that reached DEFAULT — now, or at any point according to the audit trail — and loans written
     * off. The default date is the first audit entry that set status = default (an old-system loan was imported already in
     * default, so its real default date is unknown: N/A). Outstanding at default is principal + interest + insurance less
     * what had been repaid by that date; for an old-system loan it is the Remain Amount it was imported with.
     *
     * @param  Collection<int, Loan>  $disbursed
     * @return list<array<string, mixed>>
     */
    private function defaults(Collection $disbursed): array
    {
        $defaultedAt = $disbursed->isEmpty() ? collect() : AuditLog::query()
            ->where('auditable_type', (new Loan)->getMorphClass())
            ->whereIn('auditable_id', $disbursed->modelKeys())
            ->whereIn('action', ['Loan.created', 'Loan.updated'])
            ->where('after->status', LoanStatus::Default->value)
            ->orderBy('id')
            ->get(['auditable_id', 'created_at'])
            ->unique('auditable_id')
            ->mapWithKeys(fn (AuditLog $log): array => [(int) $log->auditable_id => CarbonImmutable::parse($log->created_at)]);

        $defaulted = $disbursed->filter(fn (Loan $loan): bool => in_array($loan->status, [LoanStatus::Default, LoanStatus::WrittenOff], true) || $defaultedAt->has($loan->id))->values();
        if ($defaulted->isEmpty()) {
            return [];
        }

        $deposits = DB::table('loan_transactions')
            ->whereIn('loan_id', $defaulted->modelKeys())
            ->where('type', 'deposit')
            ->whereNull('reversed_at')
            ->get(['loan_id', 'transaction_date', 'principal', 'interest', 'insurance'])
            ->groupBy('loan_id');

        return $defaulted->map(function (Loan $loan) use ($defaultedAt, $deposits): array {
            $legacy = (bool) $loan->is_legacy_opening;
            $date = $legacy ? null : $defaultedAt->get($loan->id);
            $outstandingAtDefault = match (true) {
                $legacy => round((float) $loan->amount_approved - (float) $loan->opening_paid_principal, 2),
                $date === null => null,
                default => round(max(0.0, (float) $loan->amount_approved + (float) $loan->interest_amount + (float) $loan->insurance - (float) $loan->opening_paid_principal
                    - (float) collect($deposits->get($loan->id) ?? [])
                        ->filter(fn (object $deposit): bool => substr((string) $deposit->transaction_date, 0, 10) <= $date->toDateString())
                        ->sum(fn (object $deposit): float => (float) $deposit->principal + (float) $deposit->interest + (float) $deposit->insurance)), 2),
            };
            [$resolution, $resolvedAt] = match ($loan->status) {
                LoanStatus::Closed => ['Resolved — paid in full', $loan->closed_at?->toDateString()],
                LoanStatus::WrittenOff => ['Written off', $loan->writeOff?->written_off_on?->toDateString()],
                LoanStatus::Default => ['Unresolved', null],
                default => ['Returned to repayment', null],
            };

            return [
                'loan_id' => $loan->id,
                'loan_number' => $loan->loan_number,
                'default_date' => $date?->toDateString(),
                'loan_amount' => (float) $loan->amount_approved,
                'outstanding_at_default' => $outstandingAtDefault,
                'reason' => match (true) {
                    $legacy => 'Imported as Default from the old system',
                    $date !== null => 'End date passed with an outstanding balance',
                    default => null,
                },
                'resolution' => $resolution,
                'resolution_date' => $resolvedAt,
                'current_status' => $loan->status->label(),
                'current_status_badge' => $loan->status->badge(),
                'source' => $legacy ? self::SOURCE_LEGACY : self::SOURCE_CURRENT,
                'branch_id' => $loan->branch_id,
                'branch_name' => $loan->branch?->name,
            ];
        })->sortByDesc('loan_id')->values()->all();
    }

    /**
     * Penalty History: every penalty ever charged to the customer, on a loan or standing on its own. Outstanding is the
     * Debt Profile's penalty figure (unpaid, unwaived, on a running loan or on none) so the two always agree; a waived
     * penalty remains nothing. The system stores no reason for a penalty, so the reason is N/A except for one carried over
     * from the old system's Penalty List.
     *
     * @param  Collection<int, Loan>  $loans
     * @param  array<string, mixed>  $debt
     * @return array{totals: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    private function penalties(Customer $customer, Collection $loans, array $debt): array
    {
        $byId = $loans->keyBy('id');
        $penalties = Penalty::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('penalty_date')
            ->orderByDesc('id')
            ->get(['id', 'loan_id', 'branch_id', 'amount', 'paid_amount', 'is_waived', 'is_legacy_opening', 'penalty_date']);

        $rows = $penalties->map(function (Penalty $penalty) use ($byId): array {
            $amount = (float) $penalty->amount;
            $paid = (float) $penalty->paid_amount;
            $remain = $penalty->is_waived ? 0.0 : round(max(0.0, $amount - $paid), 2);
            $loan = $penalty->loan_id !== null ? $byId->get($penalty->loan_id) : null;

            return [
                'id' => $penalty->id,
                'date' => $penalty->penalty_date?->toDateString(),
                'loan_id' => $penalty->loan_id,
                'loan_number' => $loan?->loan_number,
                'amount' => $amount,
                'paid' => $paid,
                'remain' => $remain,
                'status' => match (true) {
                    $penalty->is_waived => 'waived',
                    $remain <= 0.004 => 'paid',
                    $paid > 0.004 => 'partially_paid',
                    default => 'unpaid',
                },
                'reason' => $penalty->is_legacy_opening ? 'Old system penalty (Penalty List import)' : null,
                'source' => $penalty->is_legacy_opening ? self::SOURCE_LEGACY : self::SOURCE_CURRENT,
                'branch_id' => $penalty->branch_id,
                'branch_name' => $loan?->branch?->name,
            ];
        });

        return [
            'totals' => [
                'charged' => round((float) $penalties->sum(fn (Penalty $penalty): float => (float) $penalty->amount), 2),
                'paid' => round((float) $penalties->sum(fn (Penalty $penalty): float => (float) $penalty->paid_amount), 2),
                'waived' => round((float) $penalties->where('is_waived', true)->sum(fn (Penalty $penalty): float => max(0.0, (float) $penalty->amount - (float) $penalty->paid_amount)), 2),
                'outstanding' => $debt['penalty'],
                'incidents' => $penalties->count(),
            ],
            'rows' => $rows->values()->all(),
        ];
    }

    /**
     * Profit / Interest History: the interest each disbursed loan of this system carries (loans.interest_amount, fixed at
     * approval) and the part collected (the interest split of its repayments). An old-system loan's interest was never
     * separated from its principal: N/A, and left out of both totals.
     *
     * @param  Collection<int, Loan>  $disbursed
     * @return array<string, mixed>
     */
    private function profit(Collection $disbursed): array
    {
        $rows = $disbursed->sortByDesc('id')->map(fn (Loan $loan): array => [
            'loan_id' => $loan->id,
            'loan_number' => $loan->loan_number,
            'date' => $loan->withdrawn_at?->toDateString() ?? $loan->created_at?->toDateString(),
            'loan_amount' => (float) $loan->amount_approved,
            'interest' => $loan->is_legacy_opening ? null : (float) $loan->interest_amount,
            'collected' => $loan->is_legacy_opening ? null : (float) $loan->paid_interest,
            'status_label' => $loan->status->label(),
            'source' => $loan->is_legacy_opening ? self::SOURCE_LEGACY : self::SOURCE_CURRENT,
            'branch_id' => $loan->branch_id,
            'branch_name' => $loan->branch?->name,
        ])->values();

        return [
            'total' => round((float) $rows->sum(fn (array $row): float => (float) ($row['interest'] ?? 0)), 2),
            'collected' => round((float) $rows->sum(fn (array $row): float => (float) ($row['collected'] ?? 0)), 2),
            'excluded_legacy_loans' => $rows->where('source', self::SOURCE_LEGACY)->count(),
            'rows' => $rows->all(),
        ];
    }

    /**
     * Salary Advance History (the customer product), apart from loans. Totals cover approved advances (active or done);
     * pending and reversed ones are listed but count nowhere. Outstanding is the Debt Profile's salary advance figure. A
     * salary advance has no default status, so `defaulted` is null (N/A).
     *
     * @param  array<string, mixed>  $debt
     * @return array{totals: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    private function salaryAdvances(Customer $customer, array $debt): array
    {
        $advances = SalaryAdvance::query()
            ->where('customer_id', $customer->id)
            ->with('branch:id,name')
            ->withSum('payments', 'amount')
            ->orderByDesc('id')
            ->get();
        $counted = $advances->filter(fn (SalaryAdvance $advance): bool => $advance->reversed_at === null && in_array($advance->status, ['active', 'done'], true));

        return [
            'totals' => [
                'count' => $counted->count(),
                'amount' => round((float) $counted->sum(fn (SalaryAdvance $advance): float => (float) $advance->amount), 2),
                'total_payable' => round((float) $counted->sum(fn (SalaryAdvance $advance): float => (float) $advance->total_payable), 2),
                'paid' => round((float) $counted->sum(fn (SalaryAdvance $advance): float => $advance->paid_amount), 2),
                'outstanding' => $debt['salary_advance'],
                'completed' => $counted->where('status', 'done')->count(),
                'active' => $counted->where('status', 'active')->count(),
                'defaulted' => null,
            ],
            'rows' => $advances->map(function (SalaryAdvance $advance): array {
                $status = $advance->reversed_at !== null ? 'reversed' : (string) $advance->status;

                return [
                    'id' => $advance->id,
                    'date' => ($advance->approved_at ?? $advance->created_at)?->toDateString(),
                    'amount' => (float) $advance->amount,
                    'total_payable' => (float) $advance->total_payable,
                    'paid' => round($advance->paid_amount, 2),
                    'remain' => in_array($status, ['active', 'done'], true) ? round($advance->remaining_amount, 2) : null,
                    'status' => $status,
                    'status_label' => match ($status) {
                        'done' => 'Completed',
                        default => ucfirst($status),
                    },
                    'source' => $advance->is_legacy_opening ? self::SOURCE_LEGACY : self::SOURCE_CURRENT,
                    'branch_id' => $advance->branch_id,
                    'branch_name' => $advance->branch?->name,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Current credit status, worst first: defaulted (a loan in DEFAULT or written off) → in arrears (a running loan past
     * due) → active and up to date → clear (every loan completed and nothing owed) → no borrowing history.
     *
     * @param  Collection<int, Loan>  $disbursed
     * @param  Collection<int, array<string, mixed>>  $loanRows
     * @param  array<string, mixed>  $debt
     * @return array{key: string, label: string, tone: string}
     */
    private function creditStatus(Collection $disbursed, Collection $loanRows, array $debt): array
    {
        return match (true) {
            $disbursed->contains(fn (Loan $loan): bool => in_array($loan->status, [LoanStatus::Default, LoanStatus::WrittenOff], true)) => ['key' => 'defaulted', 'label' => 'Defaulted', 'tone' => 'danger'],
            $loanRows->contains('in_arrears', true) => ['key' => 'arrears', 'label' => 'In arrears', 'tone' => 'warning'],
            $disbursed->contains(fn (Loan $loan): bool => in_array($loan->status, LoanStatus::repayable(), true)) => ['key' => 'active', 'label' => 'Active — up to date', 'tone' => 'success'],
            $disbursed->isNotEmpty() && $debt['total'] <= 0.004 => ['key' => 'clear', 'label' => 'Clear — all loans completed', 'tone' => 'primary'],
            $debt['total'] > 0.004 => ['key' => 'owing', 'label' => 'Owes penalty / salary advance', 'tone' => 'warning'],
            default => ['key' => 'none', 'label' => 'No borrowing history', 'tone' => 'default'],
        };
    }

    /**
     * Customer Credit Performance: a few plain sentences built only from the figures above.
     *
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $repayment
     * @param  array<string, mixed>  $penalties
     * @param  array<string, mixed>  $advances
     * @return list<string>
     */
    private function performance(Customer $customer, array $summary, array $repayment, array $penalties, array $advances): array
    {
        $money = fn (float $amount): string => 'TZS '.number_format($amount);
        $plural = fn (int $count, string $word): string => $count.' '.$word.($count === 1 ? '' : 's');

        if ($summary['total_loans'] === 0 && $advances['count'] === 0 && $penalties['incidents'] === 0) {
            return ['Customer has no borrowing history yet.'];
        }

        $lines = [];
        $since = $customer->created_at !== null ? ' since registration ('.$customer->created_at->toDateString().')' : '';
        $lines[] = sprintf(
            'Customer has taken %s%s: %d completed, %d active and %d defaulted.',
            $plural($summary['total_loans'], 'loan'), $since, $summary['completed_loans'], $summary['active_loans'], $summary['defaulted_loans'],
        );
        if ($summary['total_loans'] > 0) {
            $lines[] = sprintf('Total borrowed %s; total repaid %s.', $money($summary['total_borrowed']), $money($summary['total_repaid']));
        }
        if ($repayment['due_instalments'] > 0) {
            $lines[] = sprintf(
                '%d of %s already due were paid on time (%s%%); %d paid late and %d still unpaid.',
                $repayment['on_time'], $plural($repayment['due_instalments'], 'instalment'), $repayment['on_time_rate'], $repayment['late'], $repayment['missed'],
            );
        }
        $lines[] = $repayment['times_defaulted'] > 0
            ? sprintf('Customer has defaulted %s.', $repayment['times_defaulted'] === 1 ? 'once' : $repayment['times_defaulted'].' times')
            : 'Customer has never defaulted.';
        if ($penalties['incidents'] > 0) {
            $lines[] = sprintf('%s charged (%s), %s still outstanding.', $plural($penalties['incidents'], 'penalty'), $money($penalties['charged']), $money($penalties['outstanding']));
        }
        if ($advances['count'] > 0) {
            $lines[] = sprintf('%s taken (%s), %s still outstanding.', $plural($advances['count'], 'salary advance'), $money($advances['amount']), $money($advances['outstanding']));
        }
        $lines[] = sprintf('Current status: %s. Total outstanding %s.', $summary['credit_status']['label'], $money($summary['total_outstanding']));

        return $lines;
    }
}

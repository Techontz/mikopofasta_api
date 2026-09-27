<?php

namespace App\Services\Credit;

use App\Enums\Duration;
use App\Enums\LoanStatus;
use App\Models\Customer;
use App\Models\Loan;
use App\Services\LoanService;
use App\Services\Reports\InstalmentBehaviour;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The DATA half of the credit analysis screen (§37B, §38): everything the engine knows about ONE customer, read from
 * the records that already exist. No figure here is invented and none is scored — {@see CreditAssessment} turns these
 * readings into factors, and each reading names the table it came from.
 *
 * Sources per group:
 *  - history / lateness  loans, loan_schedules, loan_transactions (deposits, `reversed_at` excluded) replayed by
 *                        {@see InstalmentBehaviour} for the real payment date and delay of every instalment.
 *  - defaults            loans.status (default / written_off), loans.days_past_due, write_offs, loan_recoveries
 *                        (reversals excluded) and default-grade delays (31+ days) on loans that later closed.
 *  - top-ups / offsets   loans.topup_of_loan_id and the audit rows with action SETTLED_BY_TOPUP (context.amount).
 *  - obligations         the customer's other repayable loans, salary_advances less salary_advance_payments, unpaid
 *                        penalties. There is NO external-lender register in this system; that gap is stated in the
 *                        evidence rather than guessed at.
 *  - capacity            customers.take_home / monthly_income / basic_salary, customers.dependents and the income
 *                        stability dates (contract_expiry_date, retirement_date).
 *  - profitability       loan_transactions.interest + .penalty, loans.loan_fee where fee_deduct, salary advance
 *                        interest actually repaid and salary advance fees actually collected.
 *
 * Every query is bounded to the one customer. The loan being assessed is always excluded, so an application never
 * counts itself as history or as an obligation.
 */
class CustomerCreditProfile
{
    public function __construct(
        private readonly InstalmentBehaviour $behaviour,
        private readonly LoanService $loans,
    ) {}

    /**
     * Every reading for one customer, as at `$today`, ignoring the loan under assessment.
     *
     * @param  float  $loanTermDays  days until the requested loan would mature (sessions × instalment period)
     * @return array{history: array<string, mixed>, lateness: array<string, mixed>, defaults: array<string, mixed>, topups: array<string, mixed>, obligations: array<string, mixed>, capacity: array<string, mixed>, profitability: array<string, mixed>, relationship: array<string, mixed>}
     */
    public function for(Customer $customer, Loan $assessedLoan, CarbonImmutable $today, float $loanTermDays = 0): array
    {
        $previousLoans = $this->previousLoans($customer, $assessedLoan);
        $disbursed = $previousLoans->filter(fn (Loan $loan): bool => in_array($loan->status, LoanStatus::disbursed(), true))->values();
        $open = $disbursed->filter(fn (Loan $loan): bool => in_array($loan->status, LoanStatus::repayable(), true))->values();
        $outstanding = $open->mapWithKeys(fn (Loan $loan): array => [$loan->id => $this->loans->outstanding($loan)['total']]);
        $instalments = $this->dueInstalments($disbursed, $today);
        $obligations = $this->obligations($customer, $assessedLoan, $open, $outstanding);

        return [
            'history' => $this->history($disbursed, $open, $outstanding, $instalments),
            'lateness' => $this->lateness($instalments),
            'defaults' => $this->defaults($disbursed, $open, $instalments),
            'topups' => $this->topups($previousLoans),
            'obligations' => $obligations,
            'capacity' => $this->capacity($customer, $obligations['monthly_obligation'], $today, $loanTermDays),
            'profitability' => $this->profitability($customer, $disbursed),
            'relationship' => $this->relationship($customer, $disbursed, $today),
        ];
    }

    /**
     * Every loan of the customer except the one being assessed.
     *
     * @return Collection<int, Loan>
     */
    private function previousLoans(Customer $customer, Loan $assessedLoan): Collection
    {
        return Loan::query()
            ->where('customer_id', $customer->id)
            ->when($assessedLoan->exists, fn ($query) => $query->whereKeyNot($assessedLoan->id))
            ->orderBy('id')
            ->get();
    }

    /**
     * Instalments of the customer's disbursed loans that have already fallen due, with the delay
     * {@see InstalmentBehaviour} rebuilds from the actual repayments.
     *
     * @param  Collection<int, Loan>  $disbursed
     * @return Collection<int, array{id: int, loan_id: int, due_date: string, amount: float, paid_amount: float, paid_date: ?string, delay_days: ?int, is_due: bool, is_paid: bool, bucket: ?string}>
     */
    private function dueInstalments(Collection $disbursed, CarbonImmutable $today): Collection
    {
        $loanIds = $disbursed->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

        return $this->behaviour->instalments($loanIds, $today)->filter(fn (array $row): bool => $row['is_due'])->values();
    }

    /**
     * §38: previous loans, completed loans, principal borrowed and repaid, current outstanding and the share of
     * instalments already due that has actually been paid.
     *
     * @param  Collection<int, Loan>  $disbursed
     * @param  Collection<int, Loan>  $open
     * @param  Collection<int, float>  $outstanding  total outstanding keyed by open loan id
     * @param  Collection<int, array<string, mixed>>  $instalments
     * @return array<string, mixed>
     */
    private function history(Collection $disbursed, Collection $open, Collection $outstanding, Collection $instalments): array
    {
        $ended = $disbursed->filter(fn (Loan $loan): bool => in_array($loan->status, [LoanStatus::Closed, LoanStatus::Default, LoanStatus::WrittenOff], true));
        $completed = $disbursed->where('status', LoanStatus::Closed);

        $borrowed = round((float) $disbursed->sum(fn (Loan $loan): float => (float) $loan->amount_approved), 2);
        $repaid = $disbursed->isEmpty() ? 0.0 : round((float) DB::table('loan_transactions')
            ->whereIn('loan_id', $disbursed->pluck('id')->all())
            ->where('type', 'deposit')
            ->whereNull('reversed_at')
            ->sum('principal'), 2);

        $dueAmount = round((float) $instalments->sum('amount'), 2);
        $duePaid = round((float) $instalments->sum(fn (array $row): float => min($row['paid_amount'], $row['amount'])), 2);

        return [
            'previous_loans' => $disbursed->count(),
            'completed_loans' => $completed->count(),
            'ended_loans' => $ended->count(),
            'open_loans' => $open->count(),
            'completion_rate' => $ended->isEmpty() ? null : round($completed->count() / $ended->count(), 4),
            'total_principal_borrowed' => $borrowed,
            'total_principal_repaid' => $repaid,
            'principal_repaid_rate' => $borrowed > 0 ? round(min(1.0, $repaid / $borrowed), 4) : null,
            'instalments_due' => $instalments->count(),
            'instalments_due_amount' => $dueAmount,
            'instalments_paid_amount' => $duePaid,
            'repayment_rate' => $dueAmount > 0 ? round($duePaid / $dueAmount, 4) : null,
            'current_outstanding' => round((float) $outstanding->sum(), 2),
        ];
    }

    /**
     * §38: number, frequency and severity of late payments, from the Days-Past-Due buckets of {@see InstalmentBehaviour}.
     * An instalment still unpaid after its due date counts as late up to today.
     *
     * @param  Collection<int, array<string, mixed>>  $instalments
     * @return array<string, mixed>
     */
    private function lateness(Collection $instalments): array
    {
        /** @var array<string, float> $penalties */
        $penalties = config('credit.lateness.bucket_penalty');
        $buckets = array_fill_keys(InstalmentBehaviour::BUCKETS, 0);

        foreach ($instalments as $row) {
            $bucket = $row['bucket'] ?? '0';
            $buckets[$bucket] = ($buckets[$bucket] ?? 0) + 1;
        }

        $total = $instalments->count();
        $late = $total - $buckets['0'];
        $weighted = 0.0;
        foreach ($buckets as $bucket => $count) {
            $weighted += $count * (float) ($penalties[$bucket] ?? 0.0);
        }

        return [
            'instalments_due' => $total,
            'on_time' => $buckets['0'],
            'late' => $late,
            'buckets' => $buckets,
            'bucket_labels' => InstalmentBehaviour::BUCKET_LABELS,
            'bucket_penalty' => $penalties,
            'late_rate' => $total > 0 ? round($late / $total, 4) : null,
            'weighted_late_share' => $total > 0 ? round($weighted / $total, 4) : null,
            'avg_delay_days' => $total > 0 ? round((float) $instalments->avg(fn (array $row): int => max(0, (int) $row['delay_days'])), 1) : null,
            'max_delay_days' => $total > 0 ? (int) $instalments->max(fn (array $row): int => max(0, (int) $row['delay_days'])) : null,
        ];
    }

    /**
     * §38: defaults and write-off history, with the money recovered afterwards (loan_recoveries, reversals excluded).
     *
     * @param  Collection<int, Loan>  $disbursed
     * @param  Collection<int, Loan>  $open
     * @param  Collection<int, array<string, mixed>>  $instalments
     * @return array<string, mixed>
     */
    private function defaults(Collection $disbursed, Collection $open, Collection $instalments): array
    {
        $inDefault = $disbursed->where('status', LoanStatus::Default);
        $writtenOff = $disbursed->where('status', LoanStatus::WrittenOff);
        $writtenOffIds = $writtenOff->pluck('id')->all();

        $writtenOffAmount = $writtenOffIds === [] ? 0.0 : round((float) DB::table('write_offs')->whereIn('loan_id', $writtenOffIds)->sum('amount'), 2);
        $recovered = $writtenOffIds === [] ? 0.0 : round((float) DB::table('loan_recoveries')->whereIn('loan_id', $writtenOffIds)->whereNull('reversed_at')->sum('amount'), 2);

        $defaultGrade = (array) config('credit.default_history.default_grade_buckets');
        $closedIds = $disbursed->where('status', LoanStatus::Closed)->pluck('id')->all();
        $closedAfterDefaultGradeDelay = $instalments
            ->filter(fn (array $row): bool => in_array($row['loan_id'], $closedIds, true) && in_array($row['bucket'], $defaultGrade, true))
            ->pluck('loan_id')->unique()->count();

        return [
            'loans_in_default_now' => $inDefault->count(),
            'written_off_loans' => $writtenOff->count(),
            'written_off_amount' => $writtenOffAmount,
            'recovered_amount' => $recovered,
            'recovery_rate' => $writtenOffAmount > 0 ? round(min(1.0, $recovered / $writtenOffAmount), 4) : null,
            'closed_loans_with_default_grade_delay' => $closedAfterDefaultGradeDelay,
            'open_loans_past_due' => $open->filter(fn (Loan $loan): bool => (int) $loan->days_past_due > 0)->count(),
            'max_current_days_past_due' => (int) $open->max(fn (Loan $loan): int => (int) $loan->days_past_due),
        ];
    }

    /**
     * §38: previous top-ups and offset history — the loans taken as a top-up of an earlier one and the audit rows
     * (action SETTLED_BY_TOPUP) that record the balance a top-up settled internally.
     *
     * @param  Collection<int, Loan>  $previousLoans
     * @return array<string, mixed>
     */
    private function topups(Collection $previousLoans): array
    {
        $offsets = $previousLoans->isEmpty() ? collect() : DB::table('audit_logs')
            ->where('auditable_type', (new Loan)->getMorphClass())
            ->whereIn('auditable_id', $previousLoans->pluck('id')->all())
            ->where('action', 'SETTLED_BY_TOPUP')
            ->get(['context']);

        $offsetAmount = $offsets->sum(function (object $row): float {
            $context = is_string($row->context) ? json_decode($row->context, true) : (array) $row->context;

            return (float) ($context['amount'] ?? 0);
        });

        return [
            'topup_loans' => $previousLoans->filter(fn (Loan $loan): bool => $loan->topup_of_loan_id !== null && in_array($loan->status, LoanStatus::disbursed(), true))->count(),
            'offsets' => $offsets->count(),
            'offset_amount' => round((float) $offsetAmount, 2),
        ];
    }

    /**
     * §38: current obligations — the customer's other repayable loans, outstanding salary advances and unpaid
     * penalties — and the monthly load they represent. A salary advance is repaid from the next salary and a penalty is
     * already due, so both count in full against this month; an open loan counts at its monthly instalment.
     *
     * @param  Collection<int, Loan>  $open
     * @param  Collection<int, float>  $outstanding
     * @return array<string, mixed>
     */
    private function obligations(Customer $customer, Loan $assessedLoan, Collection $open, Collection $outstanding): array
    {
        $daysPerMonth = (int) config('credit.capacity.days_per_month');
        $loanMonthly = round((float) $open->sum(fn (Loan $loan): float => $this->monthlyInstalment($loan, $daysPerMonth)), 2);

        $advances = DB::table('salary_advances')
            ->where('customer_id', $customer->id)
            ->where('status', 'active')
            ->whereNull('reversed_at')
            ->get(['id', 'total_payable', 'opening_paid']);
        $advancePaid = $advances->isEmpty() ? collect() : DB::table('salary_advance_payments')
            ->whereIn('salary_advance_id', $advances->pluck('id')->all())
            ->whereNull('reversed_at')
            ->groupBy('salary_advance_id')
            ->selectRaw('salary_advance_id, SUM(amount) AS paid')
            ->pluck('paid', 'salary_advance_id');
        $advanceOutstanding = round((float) $advances->sum(fn (object $advance): float => max(0.0, (float) $advance->total_payable - (float) $advance->opening_paid - (float) ($advancePaid[$advance->id] ?? 0))), 2);

        $penalties = max(0.0, round((float) DB::table('penalties')
            ->where('customer_id', $customer->id)
            ->where('is_waived', false)
            // A penalty carried over from the old system may have no loan at all (loan_id NULL, which `!=` would drop).
            ->when($assessedLoan->exists, fn ($query) => $query->where(fn ($inner) => $inner->whereNull('loan_id')->orWhere('loan_id', '!=', $assessedLoan->id)))
            ->selectRaw('COALESCE(SUM(amount - paid_amount), 0) AS unpaid')
            ->value('unpaid'), 2));

        $loanOutstanding = round((float) $outstanding->sum(), 2);

        return [
            'other_open_loans' => $open->count(),
            'other_loans_outstanding' => $loanOutstanding,
            'other_loans_monthly' => $loanMonthly,
            'salary_advances' => $advances->count(),
            'salary_advance_outstanding' => $advanceOutstanding,
            'unpaid_penalties' => $penalties,
            'total_outstanding' => round($loanOutstanding + $advanceOutstanding + $penalties, 2),
            'monthly_obligation' => round($loanMonthly + $advanceOutstanding + $penalties, 2),
            'external_lender_obligations' => null,
        ];
    }

    /**
     * The instalment of an open loan expressed as a monthly load (a weekly 10,000 instalment is a 30/7 monthly load).
     */
    public static function monthlyInstalment(Loan $loan, int $daysPerMonth): float
    {
        $instalment = (float) $loan->instalment;
        if ($instalment <= 0) {
            $instalment = (float) $loan->restoration;
        }
        if ($instalment <= 0 && (int) $loan->sessions > 0) {
            $instalment = (float) $loan->total_payable / (int) $loan->sessions;
        }

        $days = ($loan->duration instanceof Duration ? $loan->duration : Duration::Monthly)->days();

        return round($instalment * ($daysPerMonth / $days), 2);
    }

    /**
     * §38: income, income stability and repayment capacity. The income figure is whichever configured customer column is
     * filled first, and the field used is reported so the officer can see what the capacity rests on.
     *
     * @return array<string, mixed>
     */
    private function capacity(Customer $customer, float $monthlyObligation, CarbonImmutable $today, float $loanTermDays): array
    {
        $income = 0.0;
        $field = null;
        foreach ((array) config('credit.capacity.income_fields') as $candidate) {
            $value = (float) ($customer->{$candidate} ?? 0);
            if ($value > 0) {
                $income = $value;
                $field = $candidate;
                break;
            }
        }

        $dependents = (int) ($customer->dependents ?? 0);
        $dependentShare = min((float) config('credit.capacity.max_dependent_share'), $dependents * (float) config('credit.capacity.cost_per_dependent'));
        $householdCost = round($income * $dependentShare, 2);
        $disposable = max(0.0, round($income - $householdCost - $monthlyObligation, 2));
        $sustainable = round($disposable * (float) config('credit.capacity.instalment_share'), 2);

        $maturity = $today->addDays((int) ceil($loanTermDays));
        $incomeEnds = collect([
            'contract_expiry_date' => $customer->contract_expiry_date,
            'retirement_date' => $customer->retirement_date,
        ])->filter(fn ($date): bool => $date !== null && CarbonImmutable::parse($date)->lt($maturity))
            ->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())
            ->all();

        return [
            'income_field' => $field,
            'monthly_income' => round($income, 2),
            'income_known' => $field !== null,
            'dependents' => $dependents,
            'dependent_share' => round($dependentShare, 4),
            'household_cost' => $householdCost,
            'existing_monthly_obligation' => round($monthlyObligation, 2),
            'disposable_income' => $disposable,
            'sustainable_monthly_instalment' => $sustainable,
            'debt_to_income' => $income > 0 ? round($monthlyObligation / $income, 4) : null,
            'work_status' => $customer->work_status,
            'contract_expiry_date' => $customer->contract_expiry_date?->toDateString(),
            'retirement_date' => $customer->retirement_date?->toDateString(),
            'expected_maturity_date' => $maturity->toDateString(),
            'income_ends_before_maturity' => $incomeEnds,
        ];
    }

    /**
     * §42: the customer's historical contribution to income — collected interest and penalty, loan fees deducted at
     * disbursement, salary advance interest actually repaid (payments beyond the advance amount) and salary advance fees
     * actually collected. Reported for the officer's judgement; it carries no weight.
     *
     * @param  Collection<int, Loan>  $disbursed
     * @return array<string, mixed>
     */
    private function profitability(Customer $customer, Collection $disbursed): array
    {
        $collected = $disbursed->isEmpty() ? null : DB::table('loan_transactions')
            ->whereIn('loan_id', $disbursed->pluck('id')->all())
            ->where('type', 'deposit')
            ->whereNull('reversed_at')
            ->selectRaw('COALESCE(SUM(interest), 0) AS interest, COALESCE(SUM(penalty), 0) AS penalty')
            ->first();

        $interest = round((float) ($collected->interest ?? 0), 2);
        $penalty = round((float) ($collected->penalty ?? 0), 2);
        $fees = round((float) $disbursed->where('fee_deduct', true)->sum(fn (Loan $loan): float => (float) $loan->loan_fee), 2);

        $advances = DB::table('salary_advances')
            ->where('customer_id', $customer->id)
            ->whereNull('reversed_at')
            ->whereIn('status', ['active', 'done'])
            ->get(['id', 'amount', 'fee', 'fee_journal_entry_id', 'opening_paid']);
        $advancePaid = $advances->isEmpty() ? collect() : DB::table('salary_advance_payments')
            ->whereIn('salary_advance_id', $advances->pluck('id')->all())
            ->whereNull('reversed_at')
            ->groupBy('salary_advance_id')
            ->selectRaw('salary_advance_id, SUM(amount) AS paid')
            ->pluck('paid', 'salary_advance_id');
        // Principal is repaid first; what the old system had already collected on a carried-over advance (opening_paid) was
        // never profit earned here.
        $advanceInterest = round((float) $advances->sum(fn (object $advance): float => max(0.0, (float) $advance->opening_paid + (float) ($advancePaid[$advance->id] ?? 0) - (float) $advance->amount)
            - max(0.0, (float) $advance->opening_paid - (float) $advance->amount)), 2);
        $advanceFees = round((float) $advances->whereNotNull('fee_journal_entry_id')->sum('fee'), 2);

        return [
            'interest_collected' => $interest,
            'penalty_collected' => $penalty,
            'loan_fees' => $fees,
            'salary_advance_interest' => $advanceInterest,
            'salary_advance_fees' => $advanceFees,
            'total_contribution' => round($interest + $penalty + $fees + $advanceInterest + $advanceFees, 2),
        ];
    }

    /**
     * §38: relationship duration — how long the customer has been on the books and since their first disbursed loan.
     *
     * @param  Collection<int, Loan>  $disbursed
     * @return array<string, mixed>
     */
    private function relationship(Customer $customer, Collection $disbursed, CarbonImmutable $today): array
    {
        $registered = $customer->created_at !== null ? CarbonImmutable::parse($customer->created_at) : null;
        $firstWithdrawal = $disbursed->pluck('withdrawn_at')->filter()->min();

        return [
            'registered_at' => $registered?->toDateString(),
            'relationship_months' => $registered !== null ? (int) $registered->diffInMonths($today, true) : 0,
            'first_loan_at' => $firstWithdrawal !== null ? CarbonImmutable::parse($firstWithdrawal)->toDateString() : null,
            'borrowing_months' => $firstWithdrawal !== null ? (int) CarbonImmutable::parse($firstWithdrawal)->diffInMonths($today, true) : 0,
        ];
    }
}

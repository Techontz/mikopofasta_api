<?php

namespace App\Services\Credit;

use App\Enums\Duration;
use App\Enums\LoanStatus;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanAssessment;
use App\Models\LoanCategory;
use App\Services\CustomerEligibility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * CREDIT ASSESSMENT ENGINE (§36 – §44, §63).
 *
 * Turns one loan application into an advisory recommendation the Credit Officer can argue with:
 *
 *   DATA          {@see CustomerCreditProfile} (the customer's own records) and {@see ContextualPerformance}
 *                 (customer type, branch, officer), every figure read from the tables that already hold it.
 *   ANALYSIS      one {@see CreditFactor} per indicator, each carrying its weight, its normalised value, the points it
 *                 contributed, which way it pushed and the evidence it came from.
 *   RECOMMENDATION a score out of 100 → a share of the requested amount, then the capacity ceiling, then the hard
 *                 overrides, then the product limit clamp.
 *
 * Rules this class enforces, all tunable in config/credit.php and all visible in the payload:
 *  §36  ADVISORY ONLY. Nothing here changes a status, an amount or an approval — it writes one loan_assessments row and
 *       nothing else. `advisory` is always true in the payload.
 *  §37  Never a bare number: a recommendation always ships with its factors, its evidence and its explanation.
 *  §39  Customer type performance is capped (`credit.caps.customer_type_performance`) and, with §40 and §41, bound by
 *       `credit.caps.contextual_total` — group behaviour can never replace individual history.
 *  §40  Branch performance: capped, contribution shown.
 *  §41  Officer performance: capped, contribution shown.
 *  §42  Customer profitability carries ZERO weight and is forced to a zero contribution; it can never raise the
 *       recommended amount, only inform the officer.
 *  §44  The LOAN PRODUCT IS NOT A SCORING INPUT. It appears only in `excluded_factors` and in the final hard clamp to
 *       the product's amount_from / amount_to, applied after the score is already fixed. Repayment capacity is measured
 *       against the application's own instalment (loans.restoration) — what the customer would actually pay — never
 *       against the product's identity, name or limits.
 *
 * Hard overrides (a frozen customer, incomplete KYC, a write-off history, a loan in default) cap or zero the
 * recommendation and always state their reason, reusing {@see CustomerEligibility} for the blocking rules rather than
 * restating them.
 */
class CreditAssessment
{
    /**
     * Hard ceiling on the joint share of the contextual signals (§39 – §41), independent of config/credit.php.
     */
    public const MAX_CONTEXTUAL_SHARE = 0.20;

    /**
     * Panels of the payload kept together in loan_assessments.analysis (everything else has its own column).
     */
    private const ANALYSIS_PANELS = ['loan_number', 'customer', 'customer_type', 'risk_band_label', 'as_of', 'excluded_factor_reasons', 'weights', 'caps', 'contextual_influence', 'capacity', 'summary'];

    public function __construct(
        private readonly CustomerCreditProfile $profile,
        private readonly ContextualPerformance $context,
        private readonly CustomerEligibility $eligibility,
    ) {}

    /**
     * Assess a loan application without storing anything.
     *
     * @return array<string, mixed>
     */
    public function for(Loan $loan, ?CarbonImmutable $today = null): array
    {
        $assessedAt = CarbonImmutable::now();
        $today ??= $assessedAt->startOfDay();
        $loan->loadMissing(['customer.customerCategory', 'category', 'branch', 'employee']);
        $customer = $loan->customer;

        $requested = $this->requestedAmount($loan);
        $readings = $this->profile->for($customer, $loan, $today, $this->loanTermDays($loan));
        $expectedInstalment = $this->expectedMonthlyInstalment($loan, $requested);

        $factors = $this->capContextual($this->factors($loan, $readings, $expectedInstalment, $today));
        $score = $this->score($factors);
        $eligibility = $this->eligibility->for($customer);
        $overrides = $this->overrides($readings, $eligibility);

        $steps = $this->recommend($requested, $score, $readings['capacity'], $expectedInstalment, $overrides, $loan->category);
        $band = $this->riskBand($score);

        return [
            'loan_id' => $loan->id,
            'loan_number' => $loan->loan_number,
            'customer_id' => $customer->id,
            'customer' => $customer->full_name,
            'customer_type' => $customer->customerCategory?->name,
            'requested_amount' => $requested,
            'recommended_amount' => $steps['final'],
            'recommended_ratio' => $requested > 0 ? round($steps['final'] / $requested, 4) : 0.0,
            'score' => $score,
            'risk_band' => $band['key'],
            'risk_band_label' => $band['label'],
            'advisory' => true,
            'engine_version' => (string) config('credit.engine_version'),
            'assessed_at' => $assessedAt->toIso8601String(),
            'as_of' => $today->toDateString(),
            'excluded_factors' => array_keys((array) config('credit.excluded_factors')),
            'excluded_factor_reasons' => (array) config('credit.excluded_factors'),
            'weights' => $factors->mapWithKeys(fn (CreditFactor $factor): array => [$factor->key => $factor->weight])->all(),
            'caps' => [...(array) config('credit.caps'), 'contextual_total' => $this->contextualCap(), 'profitability' => 0.0],
            'contextual_influence' => $this->contextualInfluence($factors),
            'factors' => $factors->map(fn (CreditFactor $factor): array => $factor->toArray())->values()->all(),
            'capacity' => $this->capacityPanel($readings['capacity'], $expectedInstalment),
            'limits' => $steps['limits'],
            'overrides' => $overrides,
            'steps' => collect($steps)->except('limits')->all(),
            'summary' => $this->summary($loan, $requested, $steps['final'], $score, $band, $readings),
            'explanation' => $this->explanation($requested, $steps, $factors, $overrides, $readings),
        ];
    }

    /**
     * Assess and store the snapshot the officer is shown (§36 / §37), so the recommendation stays auditable even after
     * the underlying figures move. Writes one loan_assessments row; it changes nothing on the loan.
     */
    public function record(Loan $loan, ?Employee $employee = null, ?CarbonImmutable $today = null): LoanAssessment
    {
        $payload = $this->for($loan, $today);

        return LoanAssessment::create([
            'company_id' => $loan->company_id,
            'branch_id' => $loan->branch_id,
            'loan_id' => $loan->id,
            'customer_id' => $loan->customer_id,
            'assessed_by' => $employee?->id,
            'requested_amount' => $payload['requested_amount'],
            'recommended_amount' => $payload['recommended_amount'],
            'recommended_ratio' => $payload['recommended_ratio'],
            'score' => $payload['score'],
            'risk_band' => $payload['risk_band'],
            'factors' => $payload['factors'],
            'excluded_factors' => $payload['excluded_factors'],
            'overrides' => $payload['overrides'],
            'limits' => $payload['limits'],
            'steps' => $payload['steps'],
            'analysis' => collect($payload)->only(self::ANALYSIS_PANELS)->all(),
            'explanation' => $payload['explanation'],
            'engine_version' => $payload['engine_version'],
            'assessed_at' => $payload['assessed_at'],
        ]);
    }

    /**
     * The stored snapshot a screen should show: the latest recorded assessment, or null when none was recorded.
     *
     * @return array<string, mixed>|null
     */
    public function storedFor(Loan $loan): ?array
    {
        $assessment = LoanAssessment::where('loan_id', $loan->id)->latestFirst()->first();

        return $assessment === null ? null : $this->present($assessment);
    }

    /**
     * §37 / §63: what a loan screen shows — the latest stored snapshot, or, for an application still awaiting a decision,
     * a fresh unstored preview. Read-only, and a failure to assess is reported and yields null: the advisory
     * recommendation can never break the loan screen.
     *
     * @return array<string, mixed>|null
     */
    public function forDisplay(Loan $loan): ?array
    {
        return rescue(function () use ($loan): ?array {
            $stored = $this->storedFor($loan);

            if ($stored !== null || ! in_array($loan->status, LoanStatus::inPipeline(), true)) {
                return $stored;
            }

            return ['stored' => false, ...$this->for($loan)];
        }, null, report: true);
    }

    /**
     * A stored snapshot in the same shape as a freshly computed one, flagged as stored.
     *
     * @return array<string, mixed>
     */
    public function present(LoanAssessment $assessment): array
    {
        return [
            ...collect($assessment->analysis ?? [])->only(self::ANALYSIS_PANELS)->all(),
            'id' => $assessment->id,
            'stored' => true,
            'loan_id' => $assessment->loan_id,
            'customer_id' => $assessment->customer_id,
            'requested_amount' => (float) $assessment->requested_amount,
            'recommended_amount' => (float) $assessment->recommended_amount,
            'recommended_ratio' => (float) $assessment->recommended_ratio,
            'score' => (float) $assessment->score,
            'risk_band' => $assessment->risk_band,
            'advisory' => true,
            'engine_version' => $assessment->engine_version,
            'assessed_at' => $assessment->assessed_at?->toIso8601String(),
            'assessed_by' => $assessment->assessor?->full_name,
            'excluded_factors' => $assessment->excluded_factors,
            'factors' => $assessment->factors,
            'overrides' => $assessment->overrides,
            'limits' => $assessment->limits,
            'steps' => $assessment->steps,
            'explanation' => $assessment->explanation,
        ];
    }

    /**
     * The amount under assessment: what the customer asked for, or the approved figure once a manager has set one.
     */
    private function requestedAmount(Loan $loan): float
    {
        $approved = (float) $loan->amount_approved;

        return round($approved > 0 ? $approved : (float) $loan->amount_applied, 2);
    }

    /**
     * The instalment the requested amount implies, expressed as a monthly load so it can be compared with a monthly
     * income (a weekly instalment is 30/7 of a monthly one).
     */
    private function expectedMonthlyInstalment(Loan $loan, float $requested): float
    {
        $perMonth = (int) config('credit.capacity.days_per_month');
        $monthly = CustomerCreditProfile::monthlyInstalment($loan, $perMonth);

        if ($monthly > 0) {
            return $monthly;
        }

        $days = ($loan->duration instanceof Duration ? $loan->duration : Duration::Monthly)->days();

        return round($requested / max(1, (int) $loan->sessions) * ($perMonth / $days), 2);
    }

    /**
     * Days until the requested loan would mature: its number of instalments × the instalment period.
     */
    private function loanTermDays(Loan $loan): float
    {
        return max(1, (int) $loan->sessions) * ($loan->duration instanceof Duration ? $loan->duration : Duration::Monthly)->days();
    }

    /**
     * The ordered factor list (§37B), heaviest first so the officer reads the drivers before the detail. Profitability
     * has weight 0 and therefore always sorts last.
     *
     * @param  array<string, array<string, mixed>>  $readings
     * @return Collection<int, CreditFactor>
     */
    private function factors(Loan $loan, array $readings, float $expectedInstalment, CarbonImmutable $today): Collection
    {
        $factors = new Collection([
            $this->repaymentHistoryFactor($readings['history']),
            $this->latenessFactor($readings['lateness']),
            $this->capacityFactor($readings['capacity'], $expectedInstalment),
            $this->defaultHistoryFactor($readings['defaults']),
            $this->obligationsFactor($readings['obligations'], $readings['capacity']),
            $this->relationshipFactor($readings['relationship'], $readings['history'], $readings['topups']),
            $this->customerTypeFactor($this->context->customerType($loan, $today)),
            $this->branchFactor($this->context->branch($loan, $today)),
            $this->officerFactor($this->context->officer($loan, $today)),
            $this->profitabilityFactor($readings['profitability']),
        ]);

        return $factors->sortByDesc(fn (CreditFactor $factor): float => $factor->weight)->values();
    }

    /**
     * §38: previous loans, completed loans, principal borrowed / repaid and the share of instalments already due that
     * was actually paid.
     */
    private function repaymentHistoryFactor(array $history): CreditFactor
    {
        $neutral = (float) config('credit.neutral_score');
        $completion = $history['completion_rate'];
        $repayment = $history['repayment_rate'];

        if ($history['previous_loans'] === 0) {
            $value = $neutral;
            $summary = 'First loan with us: there is no repayment history to judge, so this factor is scored neutral rather than against the customer.';
        } elseif ($completion === null) {
            $value = $repayment ?? $neutral;
            $summary = sprintf('%d loan(s) taken and none closed yet; %s of the instalments already due have been paid.', $history['previous_loans'], $this->percent($repayment));
        } else {
            $value = (float) config('credit.repayment_history.completion_share') * $completion + (float) config('credit.repayment_history.repayment_share') * ($repayment ?? $neutral);
            $summary = sprintf('%d loan(s) taken, %d completed of %d ended; %s of the instalments already due have been paid.', $history['previous_loans'], $history['completed_loans'], $history['ended_loans'], $this->percent($repayment));
        }

        return CreditFactor::make(
            'repayment_history', 'Repayment history', 'individual', $value, $summary,
            ['loans', 'loan_schedules', 'loan_transactions (deposits, reversals excluded)', 'App\Services\Reports\InstalmentBehaviour'],
            $history,
            $history['previous_loans'] === 0 ? ['No previous disbursed loan for this customer.'] : [],
        );
    }

    /**
     * §38: number and severity of late payments, weighted by the Days-Past-Due bucket each instalment fell into.
     */
    private function latenessFactor(array $lateness): CreditFactor
    {
        $neutral = (float) config('credit.neutral_score');

        if ($lateness['instalments_due'] === 0) {
            $value = $neutral;
            $summary = 'No instalment has fallen due yet, so there is no punctuality record; scored neutral.';
            $notes = ['No instalment of this customer has reached its due date.'];
        } else {
            $value = 1.0 - (float) $lateness['weighted_late_share'];
            $summary = sprintf('%d of %d instalments due were paid on time (%s late, average delay %s days, worst %s days).', $lateness['on_time'], $lateness['instalments_due'], $lateness['late'], $this->number($lateness['avg_delay_days']), $this->number($lateness['max_delay_days']));
            $notes = [];
        }

        return CreditFactor::make(
            'lateness', 'Payment punctuality', 'individual', $value, $summary,
            ['App\Services\Reports\InstalmentBehaviour (delay per instalment, DPD buckets)', 'loan_schedules', 'loan_transactions'],
            $lateness, $notes,
        );
    }

    /**
     * §38: income, dependants, existing monthly load and the instalment the requested amount would add.
     */
    private function capacityFactor(array $capacity, float $expectedInstalment): CreditFactor
    {
        $notes = ['No external-lender obligation register exists in this system, so only obligations recorded here reduce the capacity.'];

        if (! $capacity['income_known']) {
            $value = (float) config('credit.capacity.no_income_score');
            $summary = 'No income figure is recorded for this customer, so repayment capacity cannot be proven; the factor is scored low and no capacity ceiling is applied.';
            $notes[] = 'customers.take_home, customers.monthly_income and customers.basic_salary are all empty.';
        } else {
            $value = $expectedInstalment > 0 ? $capacity['sustainable_monthly_instalment'] / $expectedInstalment : 1.0;
            $summary = sprintf(
                'Monthly income %s (%s), %d dependant(s) and %s of existing monthly repayments leave %s disposable; %s a month can go to this loan against an expected instalment of %s.',
                $this->money($capacity['monthly_income']), $capacity['income_field'], $capacity['dependents'],
                $this->money($capacity['existing_monthly_obligation']), $this->money($capacity['disposable_income']),
                $this->money($capacity['sustainable_monthly_instalment']), $this->money($expectedInstalment),
            );
        }

        if ($capacity['income_known'] && $capacity['income_ends_before_maturity'] !== []) {
            $factor = (float) config('credit.capacity.income_ends_before_maturity_factor');
            $value *= $factor;
            $ends = collect($capacity['income_ends_before_maturity'])->map(fn (string $date, string $field): string => str_replace('_', ' ', $field).' '.$date)->implode(', ');
            $summary .= sprintf(' Income stability: %s falls before the loan would mature on %s, so capacity is counted at %s.', $ends, $capacity['expected_maturity_date'], $this->percent($factor));
        }
        $notes[] = 'Income stability is read from customers.work_status, contract_expiry_date and retirement_date only; the system holds no employer confirmation or income history beyond these.';

        return CreditFactor::make(
            'repayment_capacity', 'Repayment capacity', 'individual', $value, $summary,
            ['customers.take_home / monthly_income / basic_salary', 'customers.dependents', 'customers.contract_expiry_date / retirement_date', 'loans.instalment / restoration (the application\'s own repayment terms)'],
            $capacity + ['expected_monthly_instalment' => $expectedInstalment],
            $notes,
        );
    }

    /**
     * §38: defaults and write-off history, softened only by money actually recovered afterwards.
     */
    private function defaultHistoryFactor(array $defaults): CreditFactor
    {
        $config = (array) config('credit.default_history');

        if ($defaults['written_off_loans'] > 0) {
            $value = (float) $config['write_off_score'] + (float) ($defaults['recovery_rate'] ?? 0) * (float) $config['recovery_bonus'];
            $summary = sprintf('%d loan(s) written off for %s, of which %s has been recovered.', $defaults['written_off_loans'], $this->money($defaults['written_off_amount']), $this->money($defaults['recovered_amount']));
        } elseif ($defaults['loans_in_default_now'] > 0) {
            $value = (float) $config['default_score'];
            $summary = sprintf('%d loan(s) currently in default (oldest unpaid instalment %d days past due).', $defaults['loans_in_default_now'], $defaults['max_current_days_past_due']);
        } elseif ($defaults['closed_loans_with_default_grade_delay'] > 0) {
            $value = (float) $config['default_grade_delay_score'];
            $summary = sprintf('No loan is in default now, but %d closed loan(s) had an instalment paid more than 30 days late before being settled.', $defaults['closed_loans_with_default_grade_delay']);
        } else {
            $value = (float) $config['clean_score'];
            $summary = 'No default and no write-off in this customer\'s history.';
        }

        return CreditFactor::make(
            'default_history', 'Defaults and write-offs', 'individual', $value, $summary,
            ['loans.status (default, written_off)', 'loans.days_past_due', 'write_offs', 'loan_recoveries (reversals excluded)', 'App\Services\Reports\InstalmentBehaviour (31+ day delays on closed loans)'],
            $defaults,
            ['Loan status changes are not kept as history, so a default that was later paid off is recognised only by its 31+ day instalment delay.'],
        );
    }

    /**
     * §38: current obligations and the debt-to-income ratio they produce.
     */
    private function obligationsFactor(array $obligations, array $capacity): CreditFactor
    {
        $notes = ['This system holds no external-lender register: obligations outside MikopoFasta are not visible and are not assumed.'];

        if (! $capacity['income_known']) {
            $value = (float) config('credit.existing_obligations.no_income_score');
            $summary = sprintf('%s of obligations are outstanding, but with no income figure the debt-to-income ratio cannot be computed.', $this->money($obligations['total_outstanding']));
        } else {
            $maxDti = (float) config('credit.existing_obligations.max_debt_to_income');
            $dti = (float) ($capacity['debt_to_income'] ?? 0);
            $value = $maxDti > 0 ? 1.0 - ($dti / $maxDti) : 1.0;
            $summary = sprintf(
                '%d other open loan(s) (%s outstanding), %s of salary advances and %s of unpaid penalties — %s of monthly income already committed (limit %s).',
                $obligations['other_open_loans'], $this->money($obligations['other_loans_outstanding']),
                $this->money($obligations['salary_advance_outstanding']), $this->money($obligations['unpaid_penalties']),
                $this->percent($dti), $this->percent($maxDti),
            );
        }

        return CreditFactor::make(
            'existing_obligations', 'Existing obligations', 'individual', $value, $summary,
            ['loans (other repayable loans of the customer)', 'salary_advances less salary_advance_payments', 'penalties (unpaid, not waived)'],
            $obligations + ['debt_to_income' => $capacity['debt_to_income']],
            $notes,
        );
    }

    /**
     * §38: relationship duration, number of previous loans and the top-up / offset history behind them.
     */
    private function relationshipFactor(array $relationship, array $history, array $topups): CreditFactor
    {
        $matureLoans = max(1, (int) config('credit.relationship_depth.mature_loans'));
        $matureMonths = max(1, (int) config('credit.relationship_depth.mature_months'));

        $value = (min(1.0, $history['previous_loans'] / $matureLoans) + min(1.0, $relationship['relationship_months'] / $matureMonths)) / 2;

        return CreditFactor::make(
            'relationship_depth', 'Relationship depth', 'individual', $value,
            sprintf('Customer of %d month(s) with %d disbursed loan(s), %d top-up(s) and %d offset settlement(s) worth %s.',
                $relationship['relationship_months'], $history['previous_loans'], $topups['topup_loans'], $topups['offsets'], $this->money($topups['offset_amount'])),
            ['customers.created_at', 'loans.withdrawn_at', 'loans.topup_of_loan_id', 'audit_logs (action SETTLED_BY_TOPUP, context.amount)'],
            $relationship + $topups + ['previous_loans' => $history['previous_loans']],
        );
    }

    /**
     * §39: how the customer's TYPE has performed. Contextual — capped, and never a substitute for the customer's own
     * record.
     */
    private function customerTypeFactor(array $type): CreditFactor
    {
        $neutral = (float) config('credit.neutral_score');

        if (! $type['matched']) {
            $value = $neutral;
            $summary = sprintf('Customer type "%s" has %d disbursed loan(s) in the last %d months, below the %d needed to judge the group; scored neutral.', $type['segment'], $type['loans'], $type['lookback_months'], $type['minimum_sample_loans']);
        } else {
            $value = ($this->rate($type['repayment_rate']) + (1 - $this->rate($type['late_payment_rate'])) + (1 - $this->rate($type['default_rate']))) / 3;
            $summary = sprintf('Customer type "%s" over the last %d months: %s repayment rate, %s late-payment rate, %s default rate and %s days average delay across %d loan(s) of %d customer(s).',
                $type['segment'], $type['lookback_months'], $this->percentPoints($type['repayment_rate']), $this->percentPoints($type['late_payment_rate']), $this->percentPoints($type['default_rate']), $this->number($type['avg_delay_days']), $type['loans'], $type['customers']);
        }

        return CreditFactor::make(
            'customer_type_performance', 'Customer type performance', 'contextual', $value, $summary,
            ['customers.customer_category_id', 'customer_categories', 'loans (disbursed, look-back window)', 'App\Services\Reports\InstalmentBehaviour'],
            $type,
            ['Group behaviour, not individual evidence: capped at '.$this->percent((float) config('credit.caps.customer_type_performance')).' of the score so it can never replace the customer\'s own history (§39).'],
        );
    }

    /**
     * §40: branch collection quality. Contextual — capped, and its contribution is shown.
     */
    private function branchFactor(array $branch): CreditFactor
    {
        $neutral = (float) config('credit.neutral_score');

        if (! $branch['matched']) {
            $value = $neutral;
            $summary = sprintf('Branch %s has %d disbursed loan(s) in the last %d months, below the %d needed to judge it; scored neutral.', $branch['branch'] ?? 'unknown', $branch['loans'], $branch['lookback_months'], $branch['minimum_sample_loans']);
        } else {
            $value = $this->averageOf([
                $branch['collection_rate'] === null ? null : $this->rate($branch['collection_rate']),
                $branch['par30_rate'] === null ? null : 1 - $this->rate($branch['par30_rate']),
                $branch['default_rate'] === null ? null : 1 - $this->rate($branch['default_rate']),
            ], $neutral);
            $summary = sprintf('Branch %s over the last %d months: %s collection rate, %s of the portfolio more than 30 days overdue, %s default rate across %d loan(s).',
                $branch['branch'], $branch['lookback_months'], $this->percentPoints($branch['collection_rate']), $this->percentPoints($branch['par30_rate']), $this->percentPoints($branch['default_rate']), $branch['loans']);
        }

        return CreditFactor::make(
            'branch_performance', 'Branch performance', 'contextual', $value, $summary,
            ['App\Services\Reports\PortfolioReports::arrears()', 'App\Services\Reports\PortfolioReports::collections()', 'branch_period_results'],
            $branch,
            ['Contextual only: capped at '.$this->percent((float) config('credit.caps.branch_performance')).' of the score and must not override strong individual evidence (§40).'],
        );
    }

    /**
     * §41: loan officer portfolio quality. Contextual — capped, and its contribution is shown.
     */
    private function officerFactor(array $officer): CreditFactor
    {
        $neutral = (float) config('credit.neutral_score');

        if (! $officer['matched']) {
            $value = $neutral;
            $summary = $officer['officer'] === null
                ? 'No loan officer is recorded on this application, so officer performance is scored neutral.'
                : sprintf('Officer %s has %d disbursed loan(s) in the last %d months, below the %d needed to judge the portfolio; scored neutral.', $officer['officer'], $officer['loans'], $officer['lookback_months'], $officer['minimum_sample_loans']);
        } else {
            $value = $this->averageOf([
                $officer['collection_rate'] === null ? null : $this->rate($officer['collection_rate']),
                $officer['par30'] === null ? null : 1 - $this->rate($officer['par30']),
            ], $neutral);
            $summary = sprintf('Officer %s over the last %d months: %s of expected collections received, %s of their portfolio more than 30 days overdue.',
                $officer['officer'], $officer['lookback_months'], $this->percentPoints($officer['collection_rate']), $this->percentPoints($officer['par30']));
        }

        return CreditFactor::make(
            'officer_performance', 'Loan officer performance', 'contextual', $value, $summary,
            ['loans.employee_id', 'App\Services\Hrm\PerformanceMetrics::forEmployees()', 'loan_schedules', 'loan_transactions'],
            $officer,
            ['Contextual only: capped at '.$this->percent((float) config('credit.caps.officer_performance')).' of the score; the recommendation must not depend blindly on who manages the loan (§41).'],
        );
    }

    /**
     * §42: what the customer has contributed to income. SUPPORTING EVIDENCE ONLY — zero weight, zero contribution, and
     * it can never raise the recommended amount.
     */
    private function profitabilityFactor(array $profitability): CreditFactor
    {
        return CreditFactor::supporting(
            'profitability', 'Customer profitability',
            sprintf('Historical contribution to income %s: interest %s, penalty %s, loan fees %s, salary advance interest %s and fees %s.',
                $this->money($profitability['total_contribution']), $this->money($profitability['interest_collected']),
                $this->money($profitability['penalty_collected']), $this->money($profitability['loan_fees']),
                $this->money($profitability['salary_advance_interest']), $this->money($profitability['salary_advance_fees'])),
            ['loan_transactions.interest + .penalty (deposits, reversals excluded)', 'loans.loan_fee where fee_deduct', 'salary_advance_payments beyond salary_advances.amount', 'salary_advances.fee where collected (fee_journal_entry_id)'],
            $profitability,
            ['Supporting evidence only (§42): zero weight and zero contribution whatever config/credit.php says. More profit never means a larger loan — it is shown so the officer can weigh it against capacity and behaviour.', 'Salary advance fees posted at approval before fee collection existed are not identifiable per advance and are not counted.'],
        );
    }

    /**
     * §39 – §41: hold the three contextual factors inside `credit.caps.contextual_total` together, scaling them down
     * proportionally if their combined weight would ever exceed it.
     *
     * @param  Collection<int, CreditFactor>  $factors
     * @return Collection<int, CreditFactor>
     */
    private function capContextual(Collection $factors): Collection
    {
        $cap = $this->contextualCap() * 100;
        $total = $factors->where('group', 'contextual')->sum(fn (CreditFactor $factor): float => $factor->contribution);

        if ($total <= $cap || $total <= 0.0) {
            return $factors;
        }

        $scale = $cap / $total;

        return $factors->map(fn (CreditFactor $factor): CreditFactor => $factor->group === 'contextual' ? $factor->withContribution($factor->contribution * $scale) : $factor);
    }

    /**
     * The configured joint cap of the contextual signals, never above {@see self::MAX_CONTEXTUAL_SHARE} whatever the
     * configuration says, so group, branch and officer figures can never outweigh the customer's own record (§39 – §41).
     */
    private function contextualCap(): float
    {
        return max(0.0, min(self::MAX_CONTEXTUAL_SHARE, (float) config('credit.caps.contextual_total')));
    }

    /**
     * @param  Collection<int, CreditFactor>  $factors
     */
    private function score(Collection $factors): float
    {
        return round(max(0.0, min(100.0, $factors->sum(fn (CreditFactor $factor): float => $factor->contribution))), 2);
    }

    /**
     * The contextual block as the officer sees it: what the three signals actually moved, against what they were
     * allowed to move (§39 – §41).
     *
     * @param  Collection<int, CreditFactor>  $factors
     * @return array{factors: list<string>, contribution: float, cap: float, max_contribution: float, within_cap: bool}
     */
    private function contextualInfluence(Collection $factors): array
    {
        $contextual = $factors->where('group', 'contextual');
        $cap = round($this->contextualCap() * 100, 2);
        $contribution = round((float) $contextual->sum(fn (CreditFactor $factor): float => $factor->contribution), 2);

        return [
            'factors' => $contextual->map(fn (CreditFactor $factor): string => $factor->key)->values()->all(),
            'contribution' => $contribution,
            'cap' => $cap,
            'max_contribution' => round((float) $contextual->sum(fn (CreditFactor $factor): float => $factor->maxContribution), 2),
            'within_cap' => $contribution <= $cap + 0.01,
        ];
    }

    /**
     * Hard overrides. Each caps the recommendation at its ratio of the requested amount and states why; the lowest
     * applicable ratio wins. The blocking rules themselves come from {@see CustomerEligibility} — they are not restated
     * here.
     *
     * @param  array<string, array<string, mixed>>  $readings
     * @param  array<string, mixed>  $eligibility
     * @return list<array{key: string, ratio: float, reason: string}>
     */
    private function overrides(array $readings, array $eligibility): array
    {
        $configured = (array) config('credit.overrides');
        $overrides = [];

        $add = function (string $key, ?string $detail = null) use (&$overrides, $configured): void {
            if (! isset($configured[$key])) {
                return;
            }
            $overrides[] = ['key' => $key, 'ratio' => (float) $configured[$key]['ratio'], 'reason' => trim($configured[$key]['reason'].($detail !== null ? ' '.$detail : ''))];
        };

        if ($eligibility['freeze']['frozen']) {
            $add('frozen', $eligibility['freeze']['message']);
        }
        if (! $eligibility['kyc_complete']) {
            $add('kyc_incomplete', 'KYC status: '.$eligibility['kyc_status'].'.');
        }
        $otherReasons = array_values(array_filter($eligibility['reasons'], fn (string $reason): bool => ! str_contains(strtolower($reason), 'kyc')));
        if ($otherReasons !== []) {
            $add('not_eligible', implode(' ', $otherReasons));
        }
        if ($readings['defaults']['written_off_loans'] > 0) {
            $add('write_off', sprintf('%d loan(s) written off for %s.', $readings['defaults']['written_off_loans'], $this->money($readings['defaults']['written_off_amount'])));
        }
        if ($readings['defaults']['loans_in_default_now'] > 0) {
            $add('open_default', sprintf('%d loan(s) in default right now.', $readings['defaults']['loans_in_default_now']));
        }

        return $overrides;
    }

    /**
     * Score → amount, in the order the specification fixes: the score's share of the request, then the capacity
     * ceiling, then the hard overrides, then rounding, and only then the product's own limits (§44).
     *
     * @param  array<string, mixed>  $capacity
     * @param  list<array{key: string, ratio: float, reason: string}>  $overrides
     * @return array{score_ratio: float, score_amount: float, capacity_amount: float|null, override_ratio: float|null, override_amount: float|null, before_rounding: float, rounded: float, final: float, limits: array<string, mixed>}
     */
    private function recommend(float $requested, float $score, array $capacity, float $expectedInstalment, array $overrides, ?LoanCategory $product): array
    {
        $ratio = $this->scoreRatio($score);
        $amount = $requested * $ratio;
        $scoreAmount = round($amount, 2);

        $capacityAmount = null;
        if ($capacity['income_known'] && $expectedInstalment > 0) {
            $capacityAmount = round($requested * max(0.0, min(1.0, $capacity['sustainable_monthly_instalment'] / $expectedInstalment)), 2);
            $amount = min($amount, $capacityAmount);
        }

        $overrideRatio = collect($overrides)->min('ratio');
        $overrideAmount = $overrideRatio === null ? null : round($requested * (float) $overrideRatio, 2);
        if ($overrideAmount !== null) {
            $amount = min($amount, $overrideAmount);
        }

        $amount = round(max(0.0, min($amount, $requested)), 2);
        $step = (float) config('credit.recommendation.rounding_step');
        $rounded = $step > 0 ? floor($amount / $step) * $step : $amount;

        [$final, $limits] = $this->applyProductLimits($rounded, $product);

        return [
            'score_ratio' => round($ratio, 4),
            'score_amount' => $scoreAmount,
            'capacity_amount' => $capacityAmount,
            'override_ratio' => $overrideRatio === null ? null : (float) $overrideRatio,
            'override_amount' => $overrideAmount,
            'before_rounding' => $amount,
            'rounded' => round($rounded, 2),
            'final' => round($final, 2),
            'limits' => $limits,
        ];
    }

    /**
     * Linear between `floor_score` and `full_score`; below the floor the share falls linearly to nothing.
     */
    private function scoreRatio(float $score): float
    {
        $floor = (float) config('credit.recommendation.floor_score');
        $full = (float) config('credit.recommendation.full_score');
        $min = (float) config('credit.recommendation.min_ratio');
        $max = (float) config('credit.recommendation.max_ratio');

        if ($score >= $full) {
            return $max;
        }
        if ($score >= $floor) {
            return $full > $floor ? $min + ($max - $min) * (($score - $floor) / ($full - $floor)) : $max;
        }

        return $floor > 0 ? $min * ($score / $floor) : 0.0;
    }

    /**
     * §44: the ONLY place the loan product touches the recommendation — a hard clamp applied after the score is fixed,
     * never an input to it. An amount above the product maximum is clamped down; an amount below the product minimum
     * cannot be disbursed on that product at all and is reported as nil with the reason stated.
     *
     * @return array{0: float, 1: array<string, mixed>}
     */
    private function applyProductLimits(float $amount, ?LoanCategory $product): array
    {
        $limits = [
            'product' => $product?->name,
            'product_min' => $product === null ? null : (float) $product->amount_from,
            'product_max' => $product === null ? null : (float) $product->amount_to,
            'scored' => false,
            'role' => 'Hard clamp applied after scoring only (§44). The loan product is never a credit scoring factor.',
            'clamped' => false,
            'clamp_reason' => null,
        ];

        if ($product === null || ! config('credit.product_limits.enabled')) {
            return [$amount, $limits];
        }

        $min = (float) $product->amount_from;
        $max = (float) $product->amount_to;

        if ($amount > $max) {
            return [$max, [...$limits, 'clamped' => true, 'clamp_reason' => sprintf('Clamped down to the product maximum of %s for %s.', $this->money($max), $product->name)]];
        }

        if ($amount > 0 && $amount < $min && config('credit.product_limits.zero_below_minimum')) {
            return [0.0, [...$limits, 'clamped' => true, 'clamp_reason' => sprintf('The supportable amount of %s is below the product minimum of %s for %s, so this product cannot be disbursed at the recommended level.', $this->money($amount), $this->money($min), $product->name)]];
        }

        return [$amount, $limits];
    }

    /**
     * @return array{key: string, label: string, min_score: float}
     */
    private function riskBand(float $score): array
    {
        foreach ((array) config('credit.risk_bands') as $band) {
            if ($score >= (float) $band['min_score']) {
                return $band;
            }
        }

        return ['key' => 'high', 'label' => 'High risk', 'min_score' => 0.0];
    }

    /**
     * §37A: the summary panel — requested, recommended and the headline risk reading.
     *
     * @param  array<string, array<string, mixed>>  $readings
     * @param  array{key: string, label: string, min_score: float}  $band
     * @return array<string, mixed>
     */
    private function summary(Loan $loan, float $requested, float $recommended, float $score, array $band, array $readings): array
    {
        return [
            'requested_amount' => $requested,
            'recommended_amount' => $recommended,
            'difference' => round($requested - $recommended, 2),
            'score' => $score,
            'risk_band' => $band['key'],
            'risk_band_label' => $band['label'],
            'previous_loans' => $readings['history']['previous_loans'],
            'completed_loans' => $readings['history']['completed_loans'],
            'late_instalments' => $readings['lateness']['late'],
            'loans_in_default' => $readings['defaults']['loans_in_default_now'],
            'write_offs' => $readings['defaults']['written_off_loans'],
            'current_outstanding' => $readings['history']['current_outstanding'],
            'previous_topups' => $readings['topups']['topup_loans'],
            'previous_offset_amount' => $readings['topups']['offset_amount'],
            'existing_obligations' => $readings['obligations']['total_outstanding'],
            'monthly_income' => $readings['capacity']['monthly_income'],
            'sustainable_monthly_instalment' => $readings['capacity']['sustainable_monthly_instalment'],
            'customer_contribution' => $readings['profitability']['total_contribution'],
            'loan_product' => $loan->category?->name,
            'loan_product_scored' => false,
        ];
    }

    /**
     * §36 / §43: the recommendation in plain language — what was asked, what is recommended, what is holding it back and
     * which evidence said so. Never a bare number.
     *
     * @param  array{score_ratio: float, score_amount: float, capacity_amount: float|null, override_ratio: float|null, override_amount: float|null, before_rounding: float, rounded: float, final: float, limits: array<string, mixed>}  $steps
     * @param  Collection<int, CreditFactor>  $factors
     * @param  list<array{key: string, ratio: float, reason: string}>  $overrides
     * @param  array<string, array<string, mixed>>  $readings
     */
    private function explanation(float $requested, array $steps, Collection $factors, array $overrides, array $readings): string
    {
        $lines = [sprintf(
            'The requested amount is %s. Based on the customer\'s repayment history, current obligations, repayment capacity and other configured indicators, the system recommends %s.',
            $this->money($requested), $this->money($steps['final']),
        )];

        $positives = $factors->where('direction', 'positive')->sortByDesc(fn (CreditFactor $factor): float => $factor->contribution)->take(2);
        $negatives = $factors->where('direction', 'negative')->sortByDesc(fn (CreditFactor $factor): float => $factor->maxContribution - $factor->contribution)->take(2);

        foreach ($positives as $factor) {
            $lines[] = sprintf('In favour — %s: %s (from %s).', $factor->label, $factor->summary, implode('; ', $factor->evidence['sources']));
        }
        foreach ($negatives as $factor) {
            $lines[] = sprintf('Against — %s: %s (from %s).', $factor->label, $factor->summary, implode('; ', $factor->evidence['sources']));
        }

        if ($steps['capacity_amount'] !== null && $steps['capacity_amount'] < $steps['score_amount']) {
            $lines[] = sprintf(
                'Repayment capacity is the binding constraint: %s of monthly income less %s of existing repayments supports %s a month, which covers %s of the requested amount.',
                $this->money($readings['capacity']['monthly_income']), $this->money($readings['capacity']['existing_monthly_obligation']),
                $this->money($readings['capacity']['sustainable_monthly_instalment']), $this->money($steps['capacity_amount']),
            );
        }

        foreach ($overrides as $override) {
            $lines[] = sprintf('Override (%s) — the recommendation is capped at %s of the request: %s', str_replace('_', ' ', $override['key']), $this->percent($override['ratio']), $override['reason']);
        }

        if ($steps['limits']['clamped']) {
            $lines[] = 'Product limit — '.$steps['limits']['clamp_reason'].' The product itself was not scored (§44).';
        }

        $lines[] = sprintf('Customer profitability of %s was recorded as supporting evidence only and carried no weight in this recommendation (§42).', $this->money($readings['profitability']['total_contribution']));
        $lines[] = 'This is a recommendation, not a decision: the Credit Officer remains responsible, and this assessment has changed no status, amount or approval.';

        return implode(' ', $lines);
    }

    /**
     * Capacity panel of the analysis screen (§37A / §43).
     *
     * @param  array<string, mixed>  $capacity
     * @return array<string, mixed>
     */
    private function capacityPanel(array $capacity, float $expectedInstalment): array
    {
        return $capacity + [
            'expected_monthly_instalment' => $expectedInstalment,
            'capacity_cover' => $expectedInstalment > 0 ? round($capacity['sustainable_monthly_instalment'] / $expectedInstalment, 4) : null,
            'external_lender_data' => 'Not available — this system holds no external-lender obligation register.',
        ];
    }

    /**
     * A percentage-points figure (0 – 100) as a 0 – 1 reading, clamped.
     */
    private function rate(?float $points): float
    {
        return max(0.0, min(1.0, (float) $points / 100));
    }

    /**
     * Mean of the readings that exist; the neutral score when none does.
     *
     * @param  list<float|null>  $readings
     */
    private function averageOf(array $readings, float $neutral): float
    {
        $present = array_values(array_filter($readings, fn (?float $reading): bool => $reading !== null));

        return $present === [] ? $neutral : array_sum($present) / count($present);
    }

    private function money(?float $amount): string
    {
        return number_format((float) $amount, 0);
    }

    private function number(int|float|null $value): string
    {
        return $value === null ? 'n/a' : (string) $value;
    }

    /**
     * A 0 – 1 ratio as a percentage ("42%").
     */
    private function percent(?float $ratio): string
    {
        return $ratio === null ? 'n/a' : round($ratio * 100, 1).'%';
    }

    /**
     * A figure already expressed in percentage points ("42%").
     */
    private function percentPoints(?float $points): string
    {
        return $points === null ? 'n/a' : round($points, 1).'%';
    }
}

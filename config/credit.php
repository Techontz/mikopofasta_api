<?php

/*
|--------------------------------------------------------------------------
| Credit assessment engine (MASTER SPEC §36 – §44, §63)
|--------------------------------------------------------------------------
|
| Every weight, cap and threshold the engine uses lives here so the business
| can retune the recommendation without a code change. The engine itself
| never hard-codes a number that appears in this file.
|
| Rules encoded below and enforced by App\Services\Credit\CreditAssessment:
|
| §42  Customer profitability is SUPPORTING EVIDENCE ONLY. Its weight is 0
|      and must stay 0: more profit never buys a bigger loan.
| §44  The loan product is NEVER a scoring input. It appears only in
|      `excluded_factors` and as the final hard clamp in `product_limits`.
| §39  Customer type performance is contextual: it may not replace individual
|      history, so its weight is capped by `caps.customer_type_performance`.
| §40  Branch performance is contextual — `caps.branch_performance`.
| §41  Loan officer performance is contextual — `caps.officer_performance`.
|      The three together may never move the score by more than
|      `caps.contextual_total` (as a share of the 100-point score), and the
|      engine never lets that joint cap exceed 0.20 whatever is set here
|      (CreditAssessment::MAX_CONTEXTUAL_SHARE).
|
*/

return [

    /*
    | Stamped on every stored snapshot (loan_assessments.engine_version) so an old
    | recommendation is always readable against the rules that produced it. Bump it
    | whenever the weights, caps or formulas below change.
    */
    'engine_version' => env('CREDIT_ENGINE_VERSION', '1.0.0'),

    /*
    | §44. Inputs the engine is forbidden to score, published in the payload so the
    | officer (and the tests) can see the exclusion rather than take it on trust.
    */
    'excluded_factors' => [
        'loan_product' => 'The loan product is never a credit scoring factor (§44). Product amount limits are applied only as a final hard clamp on the recommendation.',
    ],

    /*
    | Share of the 100-point score each factor may contribute. Individual customer
    | evidence carries 0.90; the three contextual signals carry 0.10 together;
    | profitability carries 0.00. The individual + contextual weights sum to 1.00.
    */
    'weights' => [
        'repayment_history' => 0.25,
        'lateness' => 0.18,
        'repayment_capacity' => 0.18,
        'default_history' => 0.15,
        'existing_obligations' => 0.09,
        'relationship_depth' => 0.05,
        'customer_type_performance' => 0.05,
        'branch_performance' => 0.03,
        'officer_performance' => 0.02,
        'profitability' => 0.00,
    ],

    /*
    | Hard ceilings on a factor's contribution, as a share of the score. A factor can
    | never contribute more than its cap even if its weight is misconfigured above it.
    | `contextual_total` caps customer type + branch + officer TOGETHER (§39 – §41).
    | `profitability` is listed for visibility only: the engine forces it to 0 (§42).
    */
    'caps' => [
        'customer_type_performance' => 0.05,
        'branch_performance' => 0.03,
        'officer_performance' => 0.02,
        'contextual_total' => 0.10,
        'profitability' => 0.00,
    ],

    /*
    | Which group a factor belongs to. `contextual` factors are the ones bound by
    | caps.contextual_total; `supporting` factors carry no weight at all.
    */
    'groups' => [
        'individual' => ['repayment_history', 'lateness', 'repayment_capacity', 'default_history', 'existing_obligations', 'relationship_depth'],
        'contextual' => ['customer_type_performance', 'branch_performance', 'officer_performance'],
        'supporting' => ['profitability'],
    ],

    /*
    | A factor whose evidence does not exist yet (a first-time borrower, a branch with
    | no closed period) scores neutral instead of being punished for missing data.
    */
    'neutral_score' => 0.5,

    /*
    | Above `positive_threshold` a factor's direction reads "positive", below
    | `negative_threshold` it reads "negative", between the two "neutral".
    */
    'direction' => [
        'positive_threshold' => 0.6,
        'negative_threshold' => 0.4,
    ],

    'repayment_history' => [
        /* Split between "did previous loans end well" and "are instalments actually paid". */
        'completion_share' => 0.5,
        'repayment_share' => 0.5,
    ],

    'lateness' => [
        /*
        | Penalty per Days-Past-Due bucket of App\Services\Reports\InstalmentBehaviour.
        | The factor scores 1 − (weighted share of late instalments).
        */
        'bucket_penalty' => [
            '0' => 0.0,
            '1–7' => 0.35,
            '8–30' => 0.75,
            '31–60' => 0.90,
            '61–90' => 0.95,
            '90+' => 1.00,
        ],
    ],

    'default_history' => [
        /* Score floor once the customer has ever defaulted / been written off. */
        'clean_score' => 1.0,
        'default_score' => 0.15,
        'write_off_score' => 0.0,
        /*
        | A loan that ended CLOSED but had an instalment in a default-grade Days-Past-Due bucket (31+ days, labelled
        | "Default" by InstalmentBehaviour). Loan status history is not audited, so this delay is the only trace of a
        | default the customer later paid off.
        */
        'default_grade_delay_score' => 0.5,
        'default_grade_buckets' => ['31–60', '61–90', '90+'],
        /* Money recovered after a write-off may lift the floor by at most this much. */
        'recovery_bonus' => 0.15,
    ],

    'capacity' => [
        /*
        | Income fields tried in order (customers table). The first non-zero one is used
        | and named in the factor's evidence.
        */
        'income_fields' => ['take_home', 'monthly_income', 'basic_salary'],
        /* Household cost allowance: this share of income per dependent, capped. */
        'cost_per_dependent' => 0.05,
        'max_dependent_share' => 0.30,
        /* Share of disposable income that may go to this loan's instalment. */
        'instalment_share' => 0.40,
        /* Score used when the customer has no income figure at all. */
        'no_income_score' => 0.30,
        /* Days per month used to convert a daily / weekly instalment to a monthly load. */
        'days_per_month' => 30,
        /*
        | Income stability: when customers.contract_expiry_date or retirement_date falls before the requested loan would
        | mature, the capacity reading is multiplied by this factor and the reason is stated.
        */
        'income_ends_before_maturity_factor' => 0.5,
    ],

    'existing_obligations' => [
        /* Debt-to-income ratio at which the factor scores 0. */
        'max_debt_to_income' => 0.50,
        /* Score used when no income figure exists to divide by. */
        'no_income_score' => 0.30,
    ],

    'relationship_depth' => [
        /* A relationship is "mature" at this many previous loans / months. */
        'mature_loans' => 4,
        'mature_months' => 24,
    ],

    /*
    | The contextual signals (§39 – §41) describe populations, not the customer.
    |  - lookback_months       only loans disbursed / instalments due inside this window are read, which also bounds the
    |                          queries;
    |  - minimum_sample_loans  a customer type, branch or officer with fewer disbursed loans in the window scores neutral;
    |  - cache_seconds         the population figures are cached per company / type / branch / officer per day
    |                          (0 disables the cache). The figures used are always stored with the snapshot.
    */
    'context' => [
        'lookback_months' => 12,
        'minimum_sample_loans' => 5,
        'cache_seconds' => 3600,
    ],

    /*
    | Score → share of the requested amount. Linear between `floor_score` and
    | `full_score`; below the floor the ratio falls linearly to 0 at score 0.
    */
    'recommendation' => [
        'floor_score' => 25.0,
        'full_score' => 75.0,
        'min_ratio' => 0.25,
        'max_ratio' => 1.00,
        /* The recommendation is rounded DOWN to a multiple of this (0 disables rounding). */
        'rounding_step' => 1000,
    ],

    /*
    | Hard overrides. Each caps the recommendation at `ratio` × requested amount and
    | states its reason in the payload; the lowest applicable ratio wins.
    */
    'overrides' => [
        'frozen' => ['ratio' => 0.0, 'reason' => 'Customer is under a re-borrowing freeze; no amount can be recommended until it ends.'],
        'kyc_incomplete' => ['ratio' => 0.0, 'reason' => 'KYC is not complete; no amount can be recommended until the customer is verified.'],
        'not_eligible' => ['ratio' => 0.0, 'reason' => 'Customer does not currently meet the lending eligibility rules.'],
        'write_off' => ['ratio' => 0.0, 'reason' => 'Customer has a written-off loan in their history.'],
        'open_default' => ['ratio' => 0.25, 'reason' => 'Customer has a loan currently in default.'],
    ],

    /*
    | §44. Applied AFTER scoring, never as an input. A recommendation above the product
    | maximum is clamped down to it; one below the product minimum cannot be disbursed
    | at all and is reported as 0 with the reason stated.
    */
    'product_limits' => [
        'enabled' => true,
        'zero_below_minimum' => true,
    ],

    /*
    | Score bands shown next to the recommendation, highest first.
    */
    'risk_bands' => [
        ['key' => 'low', 'label' => 'Low risk', 'min_score' => 75.0],
        ['key' => 'moderate', 'label' => 'Moderate risk', 'min_score' => 50.0],
        ['key' => 'elevated', 'label' => 'Elevated risk', 'min_score' => 25.0],
        ['key' => 'high', 'label' => 'High risk', 'min_score' => 0.0],
    ],

    /*
    | Record a snapshot automatically when a loan application is created, so the officer
    | always opens an application that already carries its own assessment (§36).
    */
    'record_on_application' => true,
];

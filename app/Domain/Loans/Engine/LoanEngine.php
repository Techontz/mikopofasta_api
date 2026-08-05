<?php

declare(strict_types=1);

namespace App\Domain\Loans\Engine;

use App\Domain\Loans\DTOs\ScheduleInstallment;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * THE loan engine. Every schedule in the system is built here.
 *
 * The pipeline the architecture calls for, in one place:
 *
 *     Loan Product → Calculation Strategy → Loan Engine → Repayment Schedule
 *
 * Callers hand it a product and a loan, and get a plan back. They do not choose
 * a formula, do not know which strategy ran, and cannot reach the arithmetic —
 * which is what stops calculation logic reappearing in a controller, an action,
 * a report or a server action six months from now.
 *
 * ## What lives here and what does not
 *
 * Here: reading the product's configuration into LoanTerms, resolving the
 * strategy, and running it.
 *
 * Not here: fees (LoanFeeCalculator), penalties (PenaltyCalculator), allocation
 * (PaymentAllocator), ledger postings (LedgerService). Each is a separate
 * concern with its own rules, and folding any of them into pricing would make
 * a late payment or a fee change the interest that was agreed.
 */
final class LoanEngine
{
    public function __construct(
        private readonly InterestStrategyRegistry $strategies,
        private readonly RateBasisRegistry $bases,
    ) {}

    /**
     * The repayment plan for a loan, priced by its product.
     *
     * `$startDate` is explicit rather than read from the clock so a schedule
     * can be regenerated later and compared against the one that was stored —
     * a schedule nobody can reproduce is a schedule nobody can audit.
     *
     * @return list<ScheduleInstallment>
     */
    public function schedule(Loan $loan, CarbonImmutable $startDate): array
    {
        return $this->scheduleFor($this->termsFor($loan, $startDate), $this->formulaCode($loan));
    }

    /**
     * A plan from terms and a formula code, with no loan in sight.
     *
     * What the product preview screens and the verification tests use.
     *
     * @return list<ScheduleInstallment>
     */
    public function scheduleFor(LoanTerms $terms, string $formulaCode): array
    {
        return $this->strategies->get($formulaCode)->schedule($terms);
    }

    /**
     * The terms a loan is priced on — every one of them from the product,
     * except the principal and the start date, which are the loan's own.
     */
    public function termsFor(Loan $loan, CarbonImmutable $startDate): LoanTerms
    {
        $loan->loadMissing(['product.interestFormula', 'product.interestRateBasis', 'repaymentSchedule']);

        return LoanTerms::create(
            principal: $loan->principal(),
            /*
             * The rate snapshotted onto the loan at application, not the
             * product's current rate. A product repriced after a loan was
             * agreed must not silently reprice that loan — the snapshot is
             * what the borrower signed.
             */
            interestRate: $loan->interestRate(),
            tenureDays: $loan->tenure_days,
            frequencyDays: $loan->repaymentSchedule->frequency_days,
            startDate: $startDate,
            gracePeriodDays: $loan->product->grace_period_days ?? 0,
            rateBasis: $this->basisFor($loan->product),
        );
    }

    /**
     * Terms straight from a product, for previewing what it would produce.
     *
     * The administration screens use this to show an administrator the schedule
     * their configuration implies before any borrower is affected by it.
     */
    public function termsForProduct(
        LoanProduct $product,
        Money $principal,
        int $tenureDays,
        int $frequencyDays,
        CarbonImmutable $startDate,
    ): LoanTerms {
        return LoanTerms::create(
            principal: $principal,
            interestRate: $product->interestRatePercentage(),
            tenureDays: $tenureDays,
            frequencyDays: $frequencyDays,
            startDate: $startDate,
            gracePeriodDays: $product->grace_period_days ?? 0,
            rateBasis: $this->basisFor($product),
        );
    }

    /** When the final installment falls due — `loans.expected_completion_date`. */
    public function finalDueDate(Loan $loan, CarbonImmutable $startDate): CarbonImmutable
    {
        return $this->termsFor($loan, $startDate)->finalDueDate();
    }

    /**
     * The formulas an administrator may configure, and what each does.
     *
     * @return array<string, string> code => description
     */
    public function availableFormulas(): array
    {
        return $this->strategies->describeAll();
    }

    /**
     * The rate bases an administrator may configure — P2's open question, as a
     * list rather than an assumption.
     *
     * @return array<string, string> code => description
     */
    public function availableRateBases(): array
    {
        return $this->bases->describeAll();
    }

    public function supports(string $formulaCode): bool
    {
        return $this->strategies->has($formulaCode);
    }

    /**
     * What a product's rate means. Null basis → AS_CONFIGURED, i.e. exactly
     * what the system has always done.
     */
    private function basisFor(LoanProduct $product): RateBasis
    {
        $product->loadMissing('interestRateBasis');

        return $this->bases->get($product->interestRateBasis?->code);
    }

    private function formulaCode(Loan $loan): string
    {
        return $loan->product->interestFormula->code;
    }
}

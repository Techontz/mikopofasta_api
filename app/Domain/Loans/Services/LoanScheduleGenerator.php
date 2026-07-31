<?php

declare(strict_types=1);

namespace App\Domain\Loans\Services;

use App\Domain\Loans\DTOs\ScheduleInstallment;
use App\Domain\Loans\DTOs\ScheduleRequest;
use App\Domain\Loans\Enums\InterestFormulaCode;
use Carbon\CarbonImmutable;

/**
 * The ONE implementation of the repayment schedule — spec §6's
 * `LoanScheduleGenerator`.
 *
 * Runtime (approval), the seeder and the tests all call this. There is no
 * second copy of the interest arithmetic anywhere in the codebase, because two
 * copies of a financial calculation is two answers waiting to disagree.
 *
 * ## The three formulas
 *
 * Reproduced from the frontend's `generateLoanSchedule`, which documents them
 * as the domain layer's own assumptions where the business docs named the
 * three formulas without giving their exact maths:
 *
 * - SIMPLE   — total interest is `principal × rate`, spread evenly across the
 *              installments alongside equal principal.
 * - FLAT     — `rate` is charged per installment on the ORIGINAL principal, so
 *              interest does not shrink as principal is paid down. The classic
 *              flat-rate microfinance loan.
 * - REDUCING — `rate` is charged per installment on the OUTSTANDING balance
 *              (declining balance), with equal principal amortisation.
 *
 * ## Determinism
 *
 * Same inputs, same output — always. No randomness, no `now()`, no floats.
 * The start date is an explicit parameter rather than being read from the
 * clock, so a schedule can be regenerated for verification and will match the
 * one that was stored.
 *
 * ## Exactness
 *
 * All arithmetic is in integer minor units (App\Support\Money). Principal is
 * distributed so the installments sum EXACTLY to the loan principal — the last
 * installment absorbs the rounding remainder, which is what stops a loan from
 * being a cent short of its own principal.
 */
final class LoanScheduleGenerator
{
    /**
     * @return list<ScheduleInstallment>
     */
    public function generate(ScheduleRequest $request): array
    {
        $installmentCount = $this->installmentCount($request->tenureDays, $request->frequencyDays);

        return match ($request->formula) {
            InterestFormulaCode::Reducing => $this->reducing($request, $installmentCount),
            InterestFormulaCode::Simple, InterestFormulaCode::Flat => $this->flatOrSimple($request, $installmentCount),
        };
    }

    /**
     * How many installments a tenure produces at a given cadence.
     *
     * Rounded, not floored, and never below one: a 45-day tenure on a monthly
     * schedule is two installments, and a tenure shorter than one period still
     * has to be repaid once. Matches the frontend's
     * `Math.max(1, Math.round(tenureDays / frequencyDays))`.
     */
    public function installmentCount(int $tenureDays, int $frequencyDays): int
    {
        if ($frequencyDays < 1) {
            return 1;
        }

        $quotient = intdiv($tenureDays, $frequencyDays);

        if (($tenureDays % $frequencyDays) * 2 >= $frequencyDays) {
            $quotient++;
        }

        return max(1, $quotient);
    }

    /**
     * The date the final installment falls due — `loans.expected_completion_date`.
     */
    public function expectedCompletionDate(ScheduleRequest $request): CarbonImmutable
    {
        $count = $this->installmentCount($request->tenureDays, $request->frequencyDays);

        return $this->dueDate($request->startDate, $request->frequencyDays, $count);
    }

    /**
     * Declining balance: interest is charged on what is still outstanding, so
     * it falls with every installment.
     *
     * @return list<ScheduleInstallment>
     */
    private function reducing(ScheduleRequest $request, int $installmentCount): array
    {
        $outstanding = $request->principal;
        $principalParts = $request->principal->allocate($installmentCount);

        $installments = [];

        for ($i = 1; $i <= $installmentCount; $i++) {
            $isLast = $i === $installmentCount;

            // The last installment clears whatever remains, so rounding can
            // never leave a residue on a closed loan.
            $principalDue = $isLast ? $outstanding : $principalParts[$i - 1];
            $interestDue = $outstanding->percentage($request->interestRate);

            $outstanding = $outstanding->subtract($principalDue);

            $installments[] = new ScheduleInstallment(
                installmentNumber: $i,
                dueDate: $this->dueDate($request->startDate, $request->frequencyDays, $i),
                principalDue: $principalDue,
                interestDue: $interestDue,
            );
        }

        return $installments;
    }

    /**
     * SIMPLE and FLAT share a shape — a constant interest amount per
     * installment — and differ only in how the configured rate is read:
     * SIMPLE spreads one whole-tenure charge across the installments, FLAT
     * charges the rate once per installment.
     *
     * @return list<ScheduleInstallment>
     */
    private function flatOrSimple(ScheduleRequest $request, int $installmentCount): array
    {
        $totalInterest = $request->principal->percentage($request->interestRate);

        $interestPerInstallment = $request->formula === InterestFormulaCode::Simple
            ? $totalInterest->divide($installmentCount)
            : $totalInterest;

        $principalParts = $request->principal->allocate($installmentCount);

        $installments = [];

        for ($i = 1; $i <= $installmentCount; $i++) {
            $installments[] = new ScheduleInstallment(
                installmentNumber: $i,
                dueDate: $this->dueDate($request->startDate, $request->frequencyDays, $i),
                principalDue: $principalParts[$i - 1],
                interestDue: $interestPerInstallment,
            );
        }

        return $installments;
    }

    /**
     * Installment n falls due n periods after the start date.
     */
    private function dueDate(CarbonImmutable $start, int $frequencyDays, int $installmentNumber): CarbonImmutable
    {
        return $start->addDays($frequencyDays * $installmentNumber);
    }
}

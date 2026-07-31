<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use App\Models\StaffLoan;
use App\Support\Money;

/**
 * What a staff loan takes off each payslip.
 *
 * The counterpart of `SalaryAdvanceCalculator`, and deliberately simpler: §14
 * of the HR document describes a staff loan entirely as a ledger movement —
 * *Disbursement: Dr Staff Loan, Cr Staff Fund. Repayment: Dr Salary/Cash, Cr
 * Staff Loan* — and says nothing about interest or a charge fee. So there is
 * none. A staff loan is principal, recovered over an agreed number of payslips.
 *
 * ## What this replaces
 *
 * `PayrollCalculator::RECOVERY_PER_PERIOD` — a flat 50,000 deducted from anyone
 * with an outstanding loan, regardless of what they had borrowed or how much
 * was left. Its own docblock conceded the figure was picked rather than
 * derived.
 *
 * Two defects followed, and both were real money:
 *
 *   - **Recovery was never capped at what was owed.** A flat deduction against
 *     a smaller balance over-recovers, and the excess has nowhere to go.
 *   - **A loan never finished.** Nothing in the codebase assigned
 *     `StaffLoanStatus::Closed`, so `hasActiveLoan()` stayed true for ever.
 *
 * Together they compounded. Twelve simulated runs against the seeded 500,000
 * loan cleared it at the ninth and kept deducting: Staff Loan Receivable
 * reached −150,000, asserting the company owed the employee money it did not,
 * while the trial balance stayed balanced the whole way because both sides of
 * each entry moved together. That is why nothing caught it.
 */
final class StaffLoanCalculator
{
    /** What is still owed. Never negative — an overpayment is not a debt. */
    public function outstanding(StaffLoan $loan): Money
    {
        $remaining = $loan->amountMoney()->subtract($loan->recoveredMoney());

        return $remaining->isNegative() ? Money::zero() : $remaining;
    }

    /**
     * What this payslip should take.
     *
     * The principal spread evenly across the agreed periods, then capped at
     * what remains — so the last instalment collects the rounding rather than
     * leaving a few shillings outstanding for ever, and a loan nearly clear is
     * never over-recovered.
     *
     * Zero when nothing is owed, which is what stops payroll writing a
     * deduction row against a settled loan.
     */
    public function recoveryFor(StaffLoan $loan): Money
    {
        $outstanding = $this->outstanding($loan);

        if (! $outstanding->isPositive()) {
            return Money::zero();
        }

        $periods = max(1, $loan->recovery_periods);

        /*
         * Divided from the ORIGINAL amount, not from what remains: dividing the
         * remainder each time would shrink the instalment asymptotically and
         * the loan would never actually clear.
         *
         * `allocate()` rather than `divide()`, and the first (largest) part.
         * Dividing 100.00 over three periods gives 33.33, and three of those
         * leave a cent outstanding — so the loan would run a fourth period to
         * collect it. Allocating puts the remainder cents in the earliest
         * instalments, so the agreed term is the actual term.
         */
        $perPeriod = $loan->amountMoney()->allocate($periods)[0];

        return $perPeriod->greaterThan($outstanding) ? $outstanding : $perPeriod;
    }

    /**
     * Whether this recovery settles the loan.
     *
     * Asked after the deduction is decided rather than after it is applied, so
     * the caller can close the loan in the same transaction that recovers the
     * last of it.
     */
    public function settles(StaffLoan $loan, Money $recovery): bool
    {
        return ! $this->outstanding($loan)->greaterThan($recovery);
    }
}

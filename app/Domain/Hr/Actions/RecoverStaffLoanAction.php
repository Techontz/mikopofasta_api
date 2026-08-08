<?php

declare(strict_types=1);

namespace App\Domain\Hr\Actions;

use App\Domain\Hr\Enums\StaffLoanStatus;
use App\Domain\Hr\Services\StaffLoanCalculator;
use App\Enums\AuditAction;
use App\Models\StaffLoan;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\Date;

/**
 * Credits a staff loan with money recovered from a payslip, and closes it when
 * the balance clears.
 *
 * Called by FinalizePayrollAction once the deduction entry has posted — never
 * at generation, so a loan's balance cannot run ahead of the ledger that
 * recovered it.
 *
 * ## What this fixes
 *
 * Nothing in the codebase assigned `StaffLoanStatus::Closed`. A disbursed loan
 * stayed active for ever, `hasActiveLoan()` kept returning true, and payroll
 * deducted a flat 50,000 every month with no end and no cap.
 *
 * Twelve simulated runs against the seeded 500,000 loan cleared it at the ninth
 * and then kept going: `7010 Staff Loan Receivable` reached **−150,000**,
 * asserting the company owed the employee money it did not. The trial balance
 * stayed balanced the whole way, because both sides of each deduction entry
 * moved together — which is exactly why nothing caught it.
 *
 * This is the salary-advance defect of Module 5, in the sibling module that did
 * not get fixed at the time. The two now share a shape:
 * `RecoverStaffAdvanceAction` is the same twenty lines against a different debt.
 *
 * ## No ledger posting here
 *
 * The recovery is already in the books: PayrollPostingBuilder's deduction entry
 * credits `7010 Staff Loan Receivable` as part of Dr Staff Payable / Cr each
 * deduction's account. Posting again would credit the receivable twice and the
 * loan would appear to repay itself at double rate. This action moves no money;
 * it records against the loan what the payroll entry already moved.
 *
 * ## And no split, unlike an advance
 *
 * A salary advance recovery has to be divided between principal and charges,
 * because an advance is priced with interest and a fee. §14 of the HR document
 * describes a staff loan as principal only — *Disbursement: Dr Staff Loan, Cr
 * Staff Fund. Repayment: Dr Salary/Cash, Cr Staff Loan* — so the whole
 * instalment clears the receivable it created and 7010 walks to zero exactly.
 */
final class RecoverStaffLoanAction
{
    public function __construct(
        private readonly StaffLoanCalculator $calculator,
        private readonly AuditLogger $audit,
    ) {}

    public function recover(StaffLoan $loan, Money $amount, User $actor): StaffLoan
    {
        if (! $amount->isPositive()) {
            return $loan;
        }

        /*
         * Capped again here, not only in the calculator. This action is
         * reachable from any caller with an amount, and a loan must never
         * record more recovered than it was ever owed — that would show a
         * negative balance on the Staff Loan screen and a credit nobody owes.
         */
        $outstanding = $this->calculator->outstanding($loan);
        $applied = $amount->greaterThan($outstanding) ? $outstanding : $amount;

        if (! $applied->isPositive()) {
            return $loan;
        }

        $before = $loan->amount_recovered;
        $settles = $this->calculator->settles($loan, $applied);

        $loan->update([
            'amount_recovered' => $loan->recoveredMoney()->add($applied)->toDecimalString(),
            'status' => $settles ? StaffLoanStatus::Closed : $loan->status,
            'closed_at' => $settles ? Date::now() : null,
        ]);

        $this->audit->log(
            $settles ? AuditAction::StaffLoanClosed : AuditAction::StaffLoanRepaid,
            $loan,
            before: ['amount_recovered' => $before, 'status' => StaffLoanStatus::Active->value],
            after: [
                'amount_recovered' => $loan->amount_recovered,
                'status' => $loan->status->value,
                'recovered_this_period' => $applied->toDecimalString(),
            ],
            actor: $actor,
        );

        return $loan;
    }
}

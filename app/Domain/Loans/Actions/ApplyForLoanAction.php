<?php

declare(strict_types=1);

namespace App\Domain\Loans\Actions;

use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Exceptions\LoanNotEligibleException;
use App\Domain\Loans\Services\BranchApprovalRouter;
use App\Domain\Loans\Services\LoanEligibilityChecker;
use App\Domain\Loans\Services\LoanFeeCalculator;
use App\Domain\Loans\Services\LoanNumberGenerator;
use App\Domain\Loans\Services\LoanStateMachine;
use App\Enums\AuditAction;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * POST /loans — an officer submits an application (§15.2).
 *
 * The eligibility gate runs BEFORE anything is written (§6): an application
 * that fails a rule never becomes a row, so the loan book never contains a
 * loan that should not have been accepted.
 *
 * The three snapshot columns are taken here, at application time, so a later
 * product edit cannot rewrite terms already agreed with the customer.
 */
final class ApplyForLoanAction
{
    public function __construct(
        private readonly LoanEligibilityChecker $eligibility,
        private readonly LoanNumberGenerator $numbers,
        private readonly BranchApprovalRouter $router,
        private readonly LoanStateMachine $states,
        private readonly LoanFeeCalculator $fees,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(
        Customer $customer,
        LoanProduct $product,
        int $repaymentScheduleId,
        Money $principalAmount,
        int $tenureDays,
        ?int $groupId,
        User $officer,
    ): Loan {
        $product->loadMissing('repaymentSchedules');

        $violations = $this->eligibility->check(
            customer: $customer,
            product: $product,
            repaymentScheduleId: $repaymentScheduleId,
            principalAmount: $principalAmount,
            tenureDays: $tenureDays,
            customerLoans: $customer->loans()->get()->all(),
            guarantorCount: $customer->guarantors()->count(),
        );

        if ($violations !== []) {
            throw new LoanNotEligibleException($violations);
        }

        return DB::transaction(function () use (
            $customer, $product, $repaymentScheduleId, $principalAmount, $tenureDays, $groupId, $officer
        ): Loan {
            $loan = Loan::query()->create([
                'loan_number' => $this->numbers->next(),
                'customer_id' => $customer->getKey(),
                'loan_product_id' => $product->getKey(),
                'repayment_schedule_id' => $repaymentScheduleId,
                'group_id' => $groupId,

                // The loan belongs to the CUSTOMER's branch, not the officer's:
                // branch ownership follows the book, not who typed it in.
                'branch_id' => $customer->branch_id,
                'officer_id' => $officer->getKey(),

                'principal_amount' => $principalAmount->toDecimalString(),

                // Snapshots — §6.
                'interest_rate_snapshot' => $product->interestRate()->toDecimalString(),
                'penalty_rate_snapshot' => $product->penalty_rate,
                'tenure_days' => $tenureDays,
                'requires_mandate_snapshot' => $product->requires_mandate,

                /*
                 * The loan fee, snapshotted here for the same reason as the
                 * rates above: a borrower quoted a 5% fee is owed a 5% fee,
                 * whatever Settings says by the time the money moves.
                 *
                 * Spread, so a product with no `loan_fees` row contributes
                 * nothing and the three columns stay null — which says "no fee
                 * was agreed" where zero would say "a fee of nothing was".
                 * Nothing is charged until disbursement either way.
                 */
                ...($this->fees->snapshotFor($product) ?? []),

                'status' => LoanStatus::Draft,
                'created_by' => $officer->getKey(),
            ]);

            /*
             * The approval route is fixed here, at application time — D4.
             *
             * A branch with a zone gets the zone stage; one without routes
             * straight to Head Office Credit. Snapshotting it rather than
             * computing it live is what stops a configuration change from
             * rerouting a loan already in flight: moving a branch into a zone
             * next week must not insert a zone review into an application that
             * has already cleared its branch manager.
             *
             * Alongside the rate and fee snapshots above, for the same reason —
             * the terms of an agreement are what they were when it was made.
             */
            $this->router->snapshotFor($loan);

            // Recorded as draft → pending, matching the frontend, so the
            // history shows the application was raised and then submitted
            // rather than springing into existence mid-workflow.
            $this->states->recordInitial($loan, $officer);
            $this->states->transition($loan, LoanStatus::PendingManagerApproval, $officer, 'Application submitted');

            $this->audit->log(
                AuditAction::LoanApplied,
                $loan,
                after: [
                    'loan_number' => $loan->loan_number,
                    'customer_id' => $customer->getKey(),
                    'loan_product_id' => $product->getKey(),
                    'principal_amount' => $loan->principal_amount,
                    'tenure_days' => $tenureDays,
                ],
                actor: $officer,
            );

            return $loan->fresh(['customer', 'product', 'branch', 'repaymentSchedule']);
        });
    }
}

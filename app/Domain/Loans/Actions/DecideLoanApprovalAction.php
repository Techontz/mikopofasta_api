<?php

declare(strict_types=1);

namespace App\Domain\Loans\Actions;

use App\Domain\Loans\DTOs\ScheduleRequest;
use App\Domain\Loans\Enums\EMandateStatus;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Exceptions\LoanStateException;
use App\Domain\Loans\Services\LoanScheduleGenerator;
use App\Domain\Loans\Services\LoanStateMachine;
use App\Enums\AuditAction;
use App\Models\Loan;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * POST /loans/{loan}/approve-manager — the manager's decision (§15.2).
 *
 * Approval is where the repayment schedule is generated. §6 is explicit that
 * this is pure computation with no ledger involvement: the ledger is not
 * touched until a disbursement batch actually succeeds.
 *
 * Separation of duties (§14): the officer who raised the application can never
 * record its approval. That is enforced here, not merely hidden in the UI.
 */
final class DecideLoanApprovalAction
{
    public function __construct(
        private readonly LoanScheduleGenerator $generator,
        private readonly LoanStateMachine $states,
        private readonly AuditLogger $audit,
    ) {}

    public function approve(Loan $loan, User $manager): Loan
    {
        $this->guard($loan, $manager);

        return DB::transaction(function () use ($loan, $manager): Loan {
            $loan->loadMissing(['product.interestFormula', 'repaymentSchedule', 'customer.bankDetails']);

            /*
             * The schedule starts from today, not from the application date:
             * the customer's obligations run from when the loan was agreed,
             * and an application approved a week late should not arrive with
             * its first installment already a week old.
             */
            $request = new ScheduleRequest(
                principal: $loan->principal(),
                interestRate: $loan->interestRate(),
                tenureDays: $loan->tenure_days,
                frequencyDays: $loan->repaymentSchedule->frequency_days,
                formula: $loan->product->interestFormula->code,
                startDate: Date::now()->startOfDay()->toImmutable(),
            );

            foreach ($this->generator->generate($request) as $installment) {
                $loan->schedules()->create($installment->toDatabaseRow($loan->getKey()));
            }

            $loan->update([
                'approved_by' => $manager->getKey(),
                'approved_at' => Date::now(),
                'expected_completion_date' => $this->generator->expectedCompletionDate($request)->toDateString(),
            ]);

            /*
             * §10's conditional branch: a product requiring an E-Mandate goes
             * through the OTP flow first; otherwise straight to credit review.
             * The decision reads the SNAPSHOT, not the product — a product
             * edited after application must not reroute a live loan.
             */
            if ($loan->requires_mandate_snapshot) {
                $this->states->transition($loan, LoanStatus::MandatePendingOtp, $manager, 'Approved by manager');

                $loan->mandates()->create([
                    'bank_name' => $loan->customer->bankDetails->bank_name ?? 'Unknown Bank',
                    'status' => EMandateStatus::PendingOtp,
                ]);
            } else {
                $this->states->transition($loan, LoanStatus::PendingCreditReview, $manager, 'Approved by manager');
            }

            $this->audit->log(
                AuditAction::LoanApproved,
                $loan,
                after: [
                    'approved_by' => $manager->getKey(),
                    'installments' => $loan->schedules()->count(),
                    'next_status' => $loan->fresh()->status->value,
                ],
                actor: $manager,
            );

            return $loan->fresh(['customer', 'product', 'schedules', 'branch']);
        });
    }

    public function reject(Loan $loan, string $reason, User $manager): Loan
    {
        $this->guard($loan, $manager);

        return DB::transaction(function () use ($loan, $reason, $manager): Loan {
            $this->states->transition($loan, LoanStatus::Rejected, $manager, $reason);

            $loan->update(['rejected_reason' => $reason]);

            $this->audit->log(
                AuditAction::LoanRejected,
                $loan,
                after: ['rejected_reason' => $reason],
                actor: $manager,
            );

            return $loan->fresh(['customer', 'product', 'branch']);
        });
    }

    private function guard(Loan $loan, User $manager): void
    {
        if ($loan->status !== LoanStatus::PendingManagerApproval) {
            throw LoanStateException::notAwaitingManagerApproval();
        }

        // §14: "The officer who created a loan application cannot also record
        // its manager approval." An authorization check, not UI hiding.
        if ($loan->created_by === $manager->getKey()) {
            throw LoanStateException::selfApproval();
        }
    }
}

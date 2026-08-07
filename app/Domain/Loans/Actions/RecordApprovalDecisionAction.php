<?php

declare(strict_types=1);

namespace App\Domain\Loans\Actions;

use App\Domain\Loans\Engine\LoanEngine;
use App\Domain\Loans\Enums\EMandateStatus;
use App\Domain\Loans\Enums\LoanApprovalDecision as Decision;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Exceptions\LoanApprovalException;
use App\Domain\Loans\Services\CustomerReferenceGenerator;
use App\Domain\Loans\Services\LoanApprovalWorkflow;
use App\Domain\Loans\Services\LoanStateMachine;
use App\Enums\AuditAction;
use App\Models\Loan;
use App\Models\LoanApprovalDecision as DecisionRecord;
use App\Models\LoanApprovalStage;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Every decision on the approval chain, taken here.
 *
 * The client's four — Approve, Reject, Return for Modification, Hold — plus
 * Release, which undoes a hold, and Resubmit, which the originating officer
 * uses after a return.
 *
 * ## Why one action and not four
 *
 * Because the parts that must never differ are the parts that are easy to get
 * subtly wrong twice: the permission check, the separation-of-duties refusal,
 * the transaction boundary, the status-history row, the decision record and
 * the audit entry. Four actions would be four chances for a hold to skip the
 * self-approval check or a return to forget the audit log. What actually
 * differs between the decisions is one status and one flag, and that is all
 * `decide()` branches on.
 *
 * ## Everything in one transaction
 *
 * A decision that moved the loan but did not record who took it is worse than
 * no decision at all — the loan is somewhere new and nobody is accountable for
 * putting it there.
 */
final class RecordApprovalDecisionAction
{
    public function __construct(
        private readonly LoanApprovalWorkflow $workflow,
        private readonly LoanStateMachine $states,
        private readonly LoanEngine $engine,
        private readonly CustomerReferenceGenerator $references,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Clears the current stage and moves the loan to the next one.
     *
     * The schedule is generated when the FIRST stage is cleared, which is where
     * it has always been generated, and is regenerated on each pass — an
     * application returned, amended and re-approved must not keep a plan priced
     * on the terms it used to have.
     */
    public function approve(Loan $loan, User $actor, ?string $reason = null): Loan
    {
        $stage = $this->workflow->requireStage($loan);

        return $this->decide($loan, $stage, $actor, Decision::Approved, $reason, function (Loan $loan) use ($stage, $actor): LoanStatus {
            if ($this->isFirstStage($loan, $stage)) {
                $this->generateSchedule($loan);

                /*
                 * `approved_by` records the FIRST approver, which is what it has
                 * always meant and what the loan screens display. Later stages
                 * are not lost — every one of them is a row in
                 * `loan_approval_decisions`, which is where the full chain is
                 * read from.
                 */
                $loan->update(['approved_by' => $actor->getKey(), 'approved_at' => Date::now()]);
            }

            /*
             * D6 / N4 — the customer-facing reference is minted HERE, when the
             * issuing stage clears, and nowhere else.
             *
             * Which stage issues it is a property of the stage, not a code
             * comparison: the chain is configurable, and a rule that tested for
             * 'HEAD_OFFICE_CREDIT' would break the day somebody renamed or
             * reordered it.
             */
            if ($stage->issues_payment_reference) {
                $this->issuePaymentReference($loan);
            }

            $target = $this->workflow->statusAfterApproval($loan, $stage);

            if ($target === LoanStatus::MandatePendingOtp) {
                $this->openMandate($loan);
            }

            return $target;
        });
    }

    public function reject(Loan $loan, User $actor, string $reason): Loan
    {
        $stage = $this->workflow->requireStage($loan);

        return $this->decide($loan, $stage, $actor, Decision::Rejected, $reason, function (Loan $loan) use ($reason): LoanStatus {
            $loan->update(['rejected_reason' => $reason]);

            return LoanStatus::Rejected;
        });
    }

    /**
     * Sends the application back to the officer who raised it.
     *
     * Any schedule already generated is discarded. The officer is being asked
     * to change something, and a plan priced on the old terms surviving the
     * round trip is how a loan ends up disbursed against a schedule nobody
     * approved.
     */
    public function returnForModification(Loan $loan, User $actor, string $reason): Loan
    {
        $stage = $this->workflow->requireStage($loan);

        return $this->decide($loan, $stage, $actor, Decision::ReturnedForModification, $reason, function (Loan $loan): LoanStatus {
            $loan->schedules()->delete();

            $loan->update([
                'approved_by' => null,
                'approved_at' => null,
                'expected_completion_date' => null,
            ]);

            return LoanStatus::ReturnedForModification;
        });
    }

    /**
     * Pauses the loan where it stands.
     *
     * `hold_resume_status` is written in the same transaction as the status
     * change, so the loan can always be put back exactly where it was. Nothing
     * else changes: a hold costs the applicant time, not their place.
     */
    public function hold(Loan $loan, User $actor, string $reason): Loan
    {
        $stage = $this->workflow->requireStage($loan);

        return $this->decide($loan, $stage, $actor, Decision::Held, $reason, function (Loan $loan): LoanStatus {
            $loan->update(['hold_resume_status' => $loan->status]);

            return LoanStatus::OnHold;
        });
    }

    /**
     * Takes a loan off hold and returns it to the stage that paused it.
     *
     * Authorised against THAT stage, not against the hold: the person who
     * resumes a loan is putting a decision back in front of an approver, and
     * should be someone entitled to be at that point in the chain.
     */
    public function release(Loan $loan, User $actor, ?string $reason = null): Loan
    {
        if ($loan->status !== LoanStatus::OnHold) {
            throw LoanApprovalException::notOnHold();
        }

        $resume = $loan->hold_resume_status ?? throw LoanApprovalException::holdResumeUnknown();
        $stage = LoanApprovalStage::forStatus($resume) ?? $this->workflow->firstStage($loan);

        return $this->decide($loan, $stage, $actor, Decision::Released, $reason, function (Loan $loan) use ($stage): LoanStatus {
            $loan->update(['hold_resume_status' => null]);

            return $stage->loan_status;
        });
    }

    /**
     * The officer's side of a return: send the corrected application back into
     * the chain, from the first stage.
     *
     * Not a `decide()` call. This is not an approver's decision — there is no
     * stage permission to check, and the separation-of-duties rule is inverted:
     * only the branch that raised the application may resubmit it. So it is
     * recorded as its own audit action against its own status transition.
     */
    public function resubmit(Loan $loan, User $actor, ?string $note = null): Loan
    {
        if ($loan->status !== LoanStatus::ReturnedForModification) {
            throw LoanApprovalException::notReturned();
        }

        $first = $this->workflow->firstStage($loan);

        return DB::transaction(function () use ($loan, $actor, $note, $first): Loan {
            $from = $loan->status;

            $this->states->transition($loan, $first->loan_status, $actor, $note ?? 'Resubmitted after modification');

            $loan->update(['approval_stage_id' => $first->getKey()]);

            $this->record($loan, $first, Decision::Approved, $from, $first->loan_status, $note, $actor, resubmission: true);

            $this->audit->log(
                AuditAction::LoanResubmitted,
                $loan,
                before: ['status' => $from->value],
                after: ['status' => $first->loan_status->value, 'stage' => $first->code, 'note' => $note],
                actor: $actor,
            );

            return $loan->fresh(['customer', 'product', 'branch', 'approvalStage']);
        });
    }

    /**
     * The shared spine: authorise, move, record, audit — in that order and in
     * one transaction.
     *
     * @param callable(Loan): LoanStatus $apply the decision's own effect, which
     *                                          returns the status the loan should end in
     */
    private function decide(
        Loan $loan,
        LoanApprovalStage $stage,
        User $actor,
        Decision $decision,
        ?string $reason,
        callable $apply,
    ): Loan {
        $this->workflow->assertCanDecide($loan, $stage, $actor);

        return DB::transaction(function () use ($loan, $stage, $actor, $decision, $reason, $apply): Loan {
            $from = $loan->status;

            $target = $apply($loan);

            $this->states->transition($loan, $target, $actor, $this->narrative($decision, $stage, $reason));

            $loan->update(['approval_stage_id' => $this->stageIdFor($target, $stage, $decision)]);

            $this->record($loan, $stage, $decision, $from, $target, $reason, $actor);

            $this->audit->log(
                $this->auditActionFor($decision),
                $loan,
                before: ['status' => $from->value, 'stage' => $stage->code],
                after: [
                    'status' => $target->value,
                    'decision' => $decision->value,
                    'stage' => $stage->code,
                    'stage_name' => $stage->name,
                    'reason' => $reason,
                    'decided_by' => $actor->getKey(),
                ],
                actor: $actor,
            );

            return $loan->fresh(['customer', 'product', 'branch', 'schedules', 'approvalStage']);
        });
    }

    /**
     * Which stage the loan now sits at.
     *
     * Null once it has left the chain — approved past the last stage, rejected,
     * or returned to the officer. A held loan KEEPS its stage, because that is
     * where it is going back to.
     */
    private function stageIdFor(LoanStatus $target, LoanApprovalStage $stage, Decision $decision): ?int
    {
        if ($decision === Decision::Held) {
            return (int) $stage->getKey();
        }

        return LoanApprovalStage::forStatus($target)?->getKey();
    }

    private function record(
        Loan $loan,
        LoanApprovalStage $stage,
        Decision $decision,
        LoanStatus $from,
        LoanStatus $to,
        ?string $reason,
        User $actor,
        bool $resubmission = false,
    ): void {
        DecisionRecord::query()->create([
            'loan_id' => $loan->getKey(),
            'loan_approval_stage_id' => $stage->getKey(),
            'stage_code' => $resubmission ? 'RESUBMISSION' : $stage->code,
            'stage_name' => $resubmission ? 'Resubmitted by Officer' : $stage->name,
            'decision' => $decision,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'decided_by' => $actor->getKey(),
            'created_at' => Date::now(),
        ]);
    }

    private function narrative(Decision $decision, LoanApprovalStage $stage, ?string $reason): string
    {
        $head = sprintf('%s at %s', $decision->label(), $stage->name);

        return $reason === null || trim($reason) === '' ? $head : $head.' — '.$reason;
    }

    private function auditActionFor(Decision $decision): AuditAction
    {
        return match ($decision) {
            Decision::Approved => AuditAction::LoanApprovalStageCleared,
            Decision::Rejected => AuditAction::LoanRejected,
            Decision::ReturnedForModification => AuditAction::LoanReturnedForModification,
            Decision::Held => AuditAction::LoanHeld,
            Decision::Released => AuditAction::LoanReleasedFromHold,
        };
    }

    /**
     * Opens the bank e-mandate the product requires — §10's conditional branch.
     *
     * Guarded against a second row: a loan returned for modification and
     * re-approved would otherwise accumulate one mandate per pass, and the OTP
     * flow would have no way to say which of them it was verifying.
     */
    private function openMandate(Loan $loan): void
    {
        if ($loan->mandates()->exists()) {
            return;
        }

        $loan->loadMissing('customer.bankDetails');

        $loan->mandates()->create([
            'bank_name' => $loan->customer->bankDetails->bank_name ?? 'Unknown Bank',
            'status' => EMandateStatus::PendingOtp,
        ]);
    }

    /**
     * Mints the customer-facing payment reference, once.
     *
     * ## Why it is guarded
     *
     * A loan can clear the issuing stage more than once. It can be held and
     * released, or returned to the officer, corrected, and sent back up the
     * whole chain — and every one of those paths passes through credit approval
     * again. Minting a second reference would leave the customer holding one
     * that no longer matches, while payments quoting it silently stopped
     * arriving.
     *
     * So the first one stands. The reference belongs to the agreement, not to
     * the approval event that happened to create it.
     *
     * ## Why the timestamp is separate from `approved_at`
     *
     * `approved_at` is the FIRST approver, by long-standing meaning. The
     * reference is issued much later in the chain, by a different person, and
     * conflating the two would misdate the moment the customer could first pay.
     */
    private function issuePaymentReference(Loan $loan): void
    {
        if ($loan->payment_reference !== null) {
            return;
        }

        $loan->loadMissing('branch');

        $loan->update([
            'payment_reference' => $this->references->next($loan->branch),
            'payment_reference_issued_at' => Date::now(),
        ]);
    }

    private function isFirstStage(Loan $loan, LoanApprovalStage $stage): bool
    {
        return $this->workflow->firstStage($loan)->getKey() === $stage->getKey();
    }

    /**
     * Builds the repayment plan, replacing any earlier one.
     *
     * The plan comes from LoanEngine, which reads the product and picks the
     * strategy. This action does not know which formula ran and has no
     * arithmetic of its own — that is the point of the engine.
     *
     * The schedule starts from today rather than from the application date: the
     * customer's obligations run from when the loan was agreed, and an
     * application approved a week late should not arrive with its first
     * installment already a week old.
     */
    private function generateSchedule(Loan $loan): void
    {
        $loan->loadMissing(['product.interestFormula', 'product.interestRateBasis', 'repaymentSchedule']);

        $startDate = Date::now()->startOfDay()->toImmutable();

        // Replaced rather than appended to. A second pass after a modification
        // would otherwise leave the loan carrying both plans.
        $loan->schedules()->delete();

        foreach ($this->engine->schedule($loan, $startDate) as $installment) {
            $loan->schedules()->create($installment->toDatabaseRow($loan->getKey()));
        }

        $loan->update([
            'expected_completion_date' => $this->engine->finalDueDate($loan, $startDate)->toDateString(),
        ]);
    }
}

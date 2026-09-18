<?php

declare(strict_types=1);

namespace App\Domain\Reversals\Executors;

use App\Domain\Loans\Enums\LoanScheduleStatus;
use App\Domain\Reversals\Enums\ReversalType;
use App\Domain\Reversals\Exceptions\ReversalRefusedException;
use App\Domain\Reversals\Services\LoanStandingRecalculator;
use App\Enums\AuditAction;
use App\Models\JournalEntry;
use App\Models\LoanSchedule;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Cancels an accrued penalty on one installment.
 *
 * ## Why this one posts nothing
 *
 * It is the odd member of the four and the reason is OSC-1, written into
 * RunOverdueProcessAction: §5 recognises penalty income when a penalty is
 * COLLECTED, not when it accrues, so the overdue job "deliberately posts
 * NOTHING" and an unpaid penalty has never touched the ledger. There is
 * therefore no entry to mirror. Reversing one is a WAIVER — it removes a
 * charge the borrower was never going to be billed for twice.
 *
 * The corollary is the guard below: a penalty that HAS been collected is
 * income the books recognised, and taking it back means reversing the payment
 * that collected it. That is a different request with a different subject, and
 * this executor refuses to be used for it rather than quietly doing half of it.
 *
 * ## Why the amount is snapshotted
 *
 * Clearing `penalty_due` destroys the only record of what was charged. The
 * `amount` on the request, captured when it was raised, is what an auditor
 * reads afterwards to see the size of the waiver.
 */
final class PenaltyReversalExecutor implements ReversalExecutor
{
    public function __construct(
        private readonly LoanStandingRecalculator $standing,
        private readonly AuditLogger $audit,
    ) {}

    public function type(): ReversalType
    {
        return ReversalType::Penalty;
    }

    public function guard(ReversalRequest $request): void
    {
        $schedule = $this->schedule($request);

        if (! $schedule->penaltyDue()->isPositive()) {
            throw ReversalRefusedException::noPenaltyOutstanding($schedule->installment_number);
        }

        /*
         * Charged, and already collected in full. The income is on the books,
         * so this is the payment's reversal to undo, not ours.
         */
        if (! $schedule->outstandingPenalty()->isPositive()) {
            throw ReversalRefusedException::penaltyAlreadyCollected($schedule->installment_number);
        }
    }

    public function amount(ReversalRequest $request): Money
    {
        return $this->schedule($request)->outstandingPenalty();
    }

    public function describe(ReversalRequest $request): string
    {
        $schedule = $this->schedule($request);

        return sprintf(
            'Penalty on %s installment %d',
            $schedule->loan->loan_number,
            $schedule->installment_number,
        );
    }

    public function execute(ReversalRequest $request, User $approver): ?JournalEntry
    {
        // Re-read under a lock and re-check: the queue is not instantaneous,
        // and a payment may have collected this penalty since the request.
        $schedule = LoanSchedule::query()->lockForUpdate()->findOrFail($request->loan_schedule_id);
        $schedule->loadMissing('loan');
        $request->setRelation('loanSchedule', $schedule);

        $this->guard($request);

        $waived = $schedule->outstandingPenalty();

        /*
         * `penalty_due` drops to what was already PAID, not to zero. Anything
         * collected stays owed-and-settled, so the installment continues to
         * account for the income the ledger recognised — only the uncollected
         * remainder is written off.
         */
        $schedule->update([
            'penalty_due' => Money::of($schedule->penalty_paid)->toDecimalString(),
            'status' => $this->statusFor($schedule, $waived)->value,
        ]);

        $loan = $schedule->loan;

        $this->standing->recalculate(
            $loan->fresh(['schedules']),
            $approver,
            sprintf('Penalty waived on installment %d', $schedule->installment_number),
        );

        $this->audit->log(
            AuditAction::PenaltyReversed,
            $schedule,
            after: [
                'reversal_request' => $request->getKey(),
                'loan_number' => $loan->loan_number,
                'installment' => $schedule->installment_number,
                'waived' => $waived->toDecimalString(),
                'reason' => $request->reason,
                'ledger_posting' => 'none (OSC-1: penalty income is recognised on collection)',
            ],
            actor: $approver,
        );

        Log::channel('operations')->warning('Penalty reversed', [
            'loan_number' => $loan->loan_number,
            'installment' => $schedule->installment_number,
            'waived' => $waived->toDecimalString(),
            'approved_by' => $approver->getKey(),
        ]);

        return null;
    }

    /**
     * The installment's status once the uncollected penalty is gone.
     *
     * Waiving can settle an installment outright — an overdue row whose only
     * remaining balance WAS the penalty becomes paid, and that is the point of
     * the waiver.
     */
    private function statusFor(LoanSchedule $schedule, Money $waived): LoanScheduleStatus
    {
        if ($schedule->status === LoanScheduleStatus::Cancelled) {
            return LoanScheduleStatus::Cancelled;
        }

        $remaining = $schedule->outstandingTotal()->subtract($waived);

        if (! $remaining->isPositive()) {
            return LoanScheduleStatus::Paid;
        }

        if ($schedule->due_date->isBefore(Date::now()->startOfDay())) {
            return LoanScheduleStatus::Overdue;
        }

        return $schedule->totalPaid()->isPositive()
            ? LoanScheduleStatus::Partial
            : LoanScheduleStatus::Pending;
    }

    private function schedule(ReversalRequest $request): LoanSchedule
    {
        $schedule = $request->loanSchedule;

        if ($schedule === null) {
            throw ReversalRefusedException::subjectMissing('penalty');
        }

        return $schedule->loadMissing('loan');
    }
}

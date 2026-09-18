<?php

declare(strict_types=1);

namespace App\Domain\Reversals\Executors;

use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Loans\Enums\DisbursementStatus;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Services\LoanStateMachine;
use App\Domain\Repayments\Enums\PaymentStatus;
use App\Domain\Reversals\Enums\ReversalType;
use App\Domain\Reversals\Exceptions\ReversalRefusedException;
use App\Enums\AuditAction;
use App\Models\DisbursementBatch;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * Undoes a payout.
 *
 * SettleDisbursementAction posts the money out, activates the loan and starts
 * its clock. This puts all three back: the entry is mirrored, the loan returns
 * to `awaiting_disbursement`, and the disbursement date, completion date and
 * fee snapshot are cleared — a loan awaiting disbursement that still carried a
 * disbursement date would be counted as live by every arrears report, which
 * reads exactly that column.
 *
 * ## The one hard precondition
 *
 * Nothing may have been repaid. That is the ruling behind this executor, and
 * the reason is not squeamishness: the repayments were allocated against a
 * schedule generated for a loan that, after this runs, was never disbursed. If
 * a borrower has paid, the payments come off first — one reversal each, one
 * approval each — or the correction is a write-off, which is an accounting
 * decision somebody signs for.
 *
 * The check runs at request time AND again at approval time, because the queue
 * is not instantaneous: a loan with no repayments when Finance raised the
 * request can have three by the time Admin opens it.
 */
final class DisbursementReversalExecutor implements ReversalExecutor
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly LoanStateMachine $states,
        private readonly AuditLogger $audit,
    ) {}

    public function type(): ReversalType
    {
        return ReversalType::Disbursement;
    }

    public function guard(ReversalRequest $request): void
    {
        $batch = $this->batch($request);
        $loan = $batch->loan;

        if ($batch->status !== DisbursementStatus::Success) {
            throw ReversalRefusedException::disbursementNotSettled($batch->batch_reference);
        }

        if ($batch->settled_loan_id === null) {
            throw ReversalRefusedException::disbursementAlreadyReversed($batch->batch_reference);
        }

        /*
         * Arrears is allowed alongside active: a loan can fall into arrears
         * without anybody paying anything, simply by an installment coming
         * due. What is NOT allowed is money having come back, which the count
         * below is the real test of.
         */
        if (! in_array($loan->status, [LoanStatus::Active, LoanStatus::Arrears], true)) {
            throw ReversalRefusedException::loanNotReversible($loan->loan_number, $loan->status->value);
        }

        $repayments = Payment::query()
            ->where('loan_id', $loan->getKey())
            ->where('status', '!=', PaymentStatus::Reversed)
            ->count();

        if ($repayments > 0) {
            throw ReversalRefusedException::disbursementHasRepayments($loan->loan_number, $repayments);
        }
    }

    public function amount(ReversalRequest $request): Money
    {
        return $this->batch($request)->loan->principal();
    }

    public function describe(ReversalRequest $request): string
    {
        $batch = $this->batch($request);

        return sprintf('Disbursement %s (%s)', $batch->batch_reference, $batch->loan->loan_number);
    }

    public function execute(ReversalRequest $request, User $approver): ?JournalEntry
    {
        $batch = $this->batch($request);

        // Lock before the write: two approvers deciding the same batch at the
        // same moment must not both post a reversal.
        $locked = DisbursementBatch::query()->lockForUpdate()->findOrFail($batch->getKey());
        $loan = Loan::query()->lockForUpdate()->findOrFail($locked->loan_id);

        $batch->setRawAttributes($locked->getAttributes(), true);
        $batch->setRelation('loan', $loan);

        $this->guard($request);

        $entry = $locked->journalEntry === null
            ? null
            : $this->ledger->reverse($locked->journalEntry, $request->reason, $approver);

        $locked->update([
            'status' => DisbursementStatus::Failed,
            'failure_reason' => sprintf('Reversed: %s', $request->reason),
            /*
             * Released so the loan can be disbursed again. `settled_loan_id`
             * is UNIQUE and is what stops a second successful batch per loan;
             * leaving it set would make a reversed disbursement permanent by
             * accident, which is the opposite of what was approved.
             */
            'settled_loan_id' => null,
        ]);

        /*
         * `disbursement_failed`, not `awaiting_disbursement`.
         *
         * Operationally they are the same position — approved, funded, money
         * not with the borrower — but only one of them is a state the loan can
         * LEAVE. `awaiting_disbursement` is entered by preparing a batch, and
         * PrepareDisbursementAction only prepares from `pending_finance`; a
         * reversed loan parked there would have no exit at all, neither a
         * retry nor a cancellation. `disbursement_failed` is the system's
         * existing "decide what happens next" state and already offers the
         * three that make sense: retry, escalate, cancel.
         */
        $this->states->reverseTransition(
            $loan,
            LoanStatus::DisbursementFailed,
            $approver,
            sprintf('Disbursement reversed — %s', $request->reason),
        );

        $loan->update([
            'disbursement_date' => null,
            'expected_completion_date' => null,
            'fee_charged' => null,
            'closed_at' => null,
            'frozen_until' => null,
        ]);

        $this->audit->log(
            AuditAction::DisbursementReversed,
            $loan,
            after: [
                'reversal_request' => $request->getKey(),
                'batch_reference' => $locked->batch_reference,
                'reason' => $request->reason,
                'journal_entry' => $entry?->entry_number,
            ],
            actor: $approver,
        );

        Log::channel('operations')->warning('Disbursement reversed', [
            'loan_number' => $loan->loan_number,
            'batch_reference' => $locked->batch_reference,
            'approved_by' => $approver->getKey(),
            'journal_entry' => $entry?->entry_number,
        ]);

        return $entry;
    }

    private function batch(ReversalRequest $request): DisbursementBatch
    {
        $batch = $request->disbursementBatch;

        if ($batch === null) {
            throw ReversalRefusedException::subjectMissing('disbursement');
        }

        return $batch->loadMissing(['loan', 'journalEntry']);
    }
}

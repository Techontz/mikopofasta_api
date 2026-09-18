<?php

declare(strict_types=1);

namespace App\Domain\Reversals\Actions;

use App\Domain\Ledger\Enums\ReversalStatus;
use App\Domain\Reversals\Enums\ReversalType;
use App\Domain\Reversals\Exceptions\ReversalRefusedException;
use App\Domain\Reversals\Services\ReversalExecutorRegistry;
use App\Enums\AuditAction;
use App\Models\CustomerAdvancePayment;
use App\Models\DisbursementBatch;
use App\Models\JournalEntry;
use App\Models\LoanSchedule;
use App\Models\Payment;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Raising a reversal — step one of two.
 *
 * §14 makes requesting and approving different grants held by different
 * people, so this action does not reverse anything. It records what somebody
 * wants reversed, checks that it CAN be reversed now, and snapshots the amount
 * for whoever decides it.
 *
 * The guard runs here as well as at approval, on purpose. Finding out at
 * request time that a loan has repayments is a sentence in a form; finding out
 * at approval time is an approver having already decided.
 */
final class RequestReversalAction
{
    public function __construct(
        private readonly ReversalExecutorRegistry $executors,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(
        ReversalType $type,
        Payment|DisbursementBatch|LoanSchedule|CustomerAdvancePayment|JournalEntry $subject,
        string $reason,
        User $requester,
    ): ReversalRequest {
        $this->assertSubjectMatchesType($type, $subject);

        $draft = $this->draft($type, $subject, $reason, $requester);

        $executor = $this->executors->for($type);

        $executor->guard($draft);

        /*
         * One pending request per subject. Two people asking to reverse the
         * same payment would each get an approval, and the second would reverse
         * a payment that had already gone back.
         */
        if ($this->pendingExistsFor($type, $subject)) {
            throw ReversalRefusedException::alreadyPending($executor->describe($draft));
        }

        $draft->amount = $executor->amount($draft)->toDecimalString();

        return DB::transaction(function () use ($draft, $executor, $requester): ReversalRequest {
            $draft->save();

            $this->audit->log(
                AuditAction::ReversalRequested,
                $draft,
                after: [
                    'type' => $draft->reversal_type->value,
                    'subject' => $executor->describe($draft),
                    'amount' => $draft->amount,
                    'reason' => $draft->reason,
                ],
                actor: $requester,
            );

            return $draft->fresh(['journalEntry', 'payment', 'disbursementBatch', 'loanSchedule', 'advancePayment', 'loan']);
        });
    }

    /**
     * An unsaved request, fully wired to its subject.
     *
     * Built before validation rather than after, so the executors can be given
     * a ReversalRequest in both phases and never need a second code path that
     * takes a loose model.
     */
    private function draft(
        ReversalType $type,
        Payment|DisbursementBatch|LoanSchedule|CustomerAdvancePayment|JournalEntry $subject,
        string $reason,
        User $requester,
    ): ReversalRequest {
        $request = new ReversalRequest([
            'reversal_type' => $type,
            'requested_by' => $requester->getKey(),
            'reason' => $reason,
            'status' => ReversalStatus::Pending,
        ]);

        match (true) {
            $subject instanceof Payment => $this->attachPayment($request, $subject),
            $subject instanceof DisbursementBatch => $this->attachDisbursement($request, $subject),
            $subject instanceof LoanSchedule => $this->attachSchedule($request, $subject),
            $subject instanceof CustomerAdvancePayment => $this->attachAdvancePayment($request, $subject),
            $subject instanceof JournalEntry => $this->attachEntry($request, $subject),
        };

        return $request;
    }

    private function attachPayment(ReversalRequest $request, Payment $payment): void
    {
        $request->payment_id = $payment->getKey();
        $request->loan_id = $payment->loan_id;
        $request->journal_entry_id = $payment->journal_entry_id;
        $request->setRelation('payment', $payment);
    }

    private function attachDisbursement(ReversalRequest $request, DisbursementBatch $batch): void
    {
        $request->disbursement_batch_id = $batch->getKey();
        $request->loan_id = $batch->loan_id;
        $request->journal_entry_id = $batch->journal_entry_id;
        $request->setRelation('disbursementBatch', $batch);
    }

    private function attachSchedule(ReversalRequest $request, LoanSchedule $schedule): void
    {
        $request->loan_schedule_id = $schedule->getKey();
        $request->loan_id = $schedule->loan_id;
        // No journal entry: accrued penalty has never reached the ledger.
        $request->setRelation('loanSchedule', $schedule);
    }

    private function attachAdvancePayment(ReversalRequest $request, CustomerAdvancePayment $payment): void
    {
        $request->customer_advance_payment_id = $payment->getKey();
        $request->journal_entry_id = $payment->journal_entry_id;
        $request->setRelation('advancePayment', $payment);
    }

    private function attachEntry(ReversalRequest $request, JournalEntry $entry): void
    {
        $request->journal_entry_id = $entry->getKey();
        $request->setRelation('journalEntry', $entry);
    }

    private function pendingExistsFor(
        ReversalType $type,
        Payment|DisbursementBatch|LoanSchedule|CustomerAdvancePayment|JournalEntry $subject,
    ): bool {
        $column = match ($type) {
            ReversalType::Payment => 'payment_id',
            ReversalType::Disbursement => 'disbursement_batch_id',
            ReversalType::Penalty => 'loan_schedule_id',
            ReversalType::AdvancePayment => 'customer_advance_payment_id',
            ReversalType::Ledger => 'journal_entry_id',
        };

        return ReversalRequest::query()
            ->where($column, $subject->getKey())
            ->where('status', ReversalStatus::Pending)
            ->exists();
    }

    /**
     * The type and the subject are supplied separately by the controller, and
     * a mismatch would hand an executor a model it cannot read.
     */
    private function assertSubjectMatchesType(
        ReversalType $type,
        Payment|DisbursementBatch|LoanSchedule|CustomerAdvancePayment|JournalEntry $subject,
    ): void {
        $expected = match ($type) {
            ReversalType::Payment => Payment::class,
            ReversalType::Disbursement => DisbursementBatch::class,
            ReversalType::Penalty => LoanSchedule::class,
            ReversalType::AdvancePayment => CustomerAdvancePayment::class,
            ReversalType::Ledger => JournalEntry::class,
        };

        if (! $subject instanceof $expected) {
            throw ReversalRefusedException::subjectMissing($type->value);
        }
    }
}

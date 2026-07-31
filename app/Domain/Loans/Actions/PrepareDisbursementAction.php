<?php

declare(strict_types=1);

namespace App\Domain\Loans\Actions;

use App\Domain\Loans\Enums\DisbursementChannel;
use App\Domain\Loans\Enums\DisbursementStatus;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Exceptions\LoanStateException;
use App\Domain\Loans\Services\LoanStateMachine;
use App\Enums\AuditAction;
use App\Models\DisbursementBatch;
use App\Models\Loan;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * `POST /loans/{loan}/prepare-disbursement` — Finance generates the batch
 * (§15.2).
 *
 * §6 describes disbursement as hybrid automated+manual: the system prepares a
 * batch and calls the provider, but a human still confirms in the external
 * portal, and it is the CALLBACK that flips the batch to success or failure.
 * The system never assumes success from its own outbound call.
 *
 * That callback is deliberately not implemented in this phase. §6 is equally
 * explicit that "no ledger entry exists until a disbursement batch reaches
 * success", and the ledger is Phase 6 — so settling a batch here would either
 * activate a loan with no ledger entry behind it, or duplicate the posting
 * logic that belongs in LedgerService. Preparation and retry are complete;
 * settlement arrives with the ledger.
 *
 * The maximum attempt count is spec §6's ("After 3 failed attempts, loan
 * status → escalated").
 */
final class PrepareDisbursementAction
{
    /**
     * §6: after this many failed attempts the loan is escalated for a manual
     * decision rather than retried again.
     */
    public const int MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly LoanStateMachine $states,
        private readonly AuditLogger $audit,
    ) {}

    public function prepare(Loan $loan, DisbursementChannel $channel, User $financeUser): DisbursementBatch
    {
        if ($loan->status !== LoanStatus::PendingFinance) {
            throw LoanStateException::notAwaitingDisbursement();
        }

        return DB::transaction(function () use ($loan, $channel, $financeUser): DisbursementBatch {
            $batch = $this->createBatch($loan, $channel, $financeUser, attemptNumber: 1);

            $this->states->transition(
                $loan,
                LoanStatus::AwaitingDisbursement,
                $financeUser,
                'Disbursement batch prepared',
            );

            $this->audit->log(
                AuditAction::LoanDisbursementPrepared,
                $loan,
                after: [
                    'batch_reference' => $batch->batch_reference,
                    'channel' => $channel->value,
                    'attempt_number' => 1,
                ],
                actor: $financeUser,
            );

            return $batch;
        });
    }

    /**
     * `POST /loans/{loan}/retry-disbursement` (§15.2).
     *
     * A retry never mutates the failed batch — it inserts a new one with
     * attempt_number+1 and a fresh reference (§6), so the trail of failures
     * survives. Exhausting the attempts escalates rather than retrying
     * forever.
     */
    public function retry(Loan $loan, User $financeUser): DisbursementBatch
    {
        if ($loan->status !== LoanStatus::DisbursementFailed) {
            throw LoanStateException::noFailedDisbursement();
        }

        $attempts = $loan->disbursementBatches()->count();

        if ($attempts >= self::MAX_ATTEMPTS) {
            /*
             * Escalate rather than silently refuse, so the loan lands in a
             * state a human is expected to act on (§6).
             *
             * The escalation commits BEFORE the exception is raised. Throwing
             * from inside the transaction would roll the escalation back, and
             * the loan would sit in disbursement_failed forever — retryable
             * again and again, with the attempt cap never actually biting.
             */
            DB::transaction(function () use ($loan, $financeUser): void {
                $this->states->transition(
                    $loan,
                    LoanStatus::Escalated,
                    $financeUser,
                    sprintf('Escalated after %d failed disbursement attempts', self::MAX_ATTEMPTS),
                );

                $loan->disbursementBatches()->latest('id')->first()?->update([
                    'status' => DisbursementStatus::Escalated,
                ]);
            });

            throw LoanStateException::disbursementAttemptsExhausted(self::MAX_ATTEMPTS);
        }

        return DB::transaction(function () use ($loan, $financeUser, $attempts): DisbursementBatch {
            $previous = $loan->disbursementBatches()->latest('id')->firstOrFail();

            $batch = $this->createBatch(
                $loan,
                $previous->channel,
                $financeUser,
                attemptNumber: $attempts + 1,
            );

            $this->states->transition(
                $loan,
                LoanStatus::AwaitingDisbursement,
                $financeUser,
                'Disbursement retried',
            );

            $this->audit->log(
                AuditAction::DisbursementRetried,
                $loan,
                before: ['previous_batch' => $previous->batch_reference],
                after: ['batch_reference' => $batch->batch_reference, 'attempt_number' => $batch->attempt_number],
                actor: $financeUser,
            );

            return $batch;
        });
    }

    private function createBatch(
        Loan $loan,
        DisbursementChannel $channel,
        User $financeUser,
        int $attemptNumber,
    ): DisbursementBatch {
        return $loan->disbursementBatches()->create([
            'batch_reference' => $this->reference($loan, $attemptNumber),
            'attempt_number' => $attemptNumber,
            'channel' => $channel,
            'status' => DisbursementStatus::Pending,
            'requested_by' => $financeUser->getKey(),
            'requested_at' => Date::now(),
        ]);
    }

    /**
     * Mirrors the frontend's `disbursementBatchReference`: VODA{loan}{-R{n}}.
     */
    private function reference(Loan $loan, int $attemptNumber): string
    {
        $suffix = $attemptNumber > 1 ? '-R'.$attemptNumber : '';

        return 'VODA'.$loan->getKey().$suffix;
    }
}

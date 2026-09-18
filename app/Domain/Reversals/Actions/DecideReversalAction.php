<?php

declare(strict_types=1);

namespace App\Domain\Reversals\Actions;

use App\Domain\Ledger\Enums\ReversalStatus;
use App\Domain\Reversals\Exceptions\ReversalRefusedException;
use App\Domain\Reversals\Services\ReversalExecutorRegistry;
use App\Enums\AuditAction;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Deciding a reversal — step two of two.
 *
 * Approving is the only place in the system where a committed financial fact
 * is undone, so three things are true of this action and none of them are
 * negotiable:
 *
 *   1. The approver is not the requester (§14). The same rule as loan
 *      approval: one person must not be able to move money and bless the
 *      movement. `ledger.reverse.approve` is a different grant from
 *      `ledger.reverse.request` for the same reason, and Finance holds both so
 *      that a second Finance officer — never the same one — can decide.
 *
 *   2. The guard runs AGAIN, under the executor's own locks. A request that
 *      was reversible when it was raised may not be when it is decided, and
 *      the queue can be hours old.
 *
 *   3. Everything commits together. A request marked approved whose executor
 *      half-ran would be a reversal nobody can find and nobody can repeat.
 */
final class DecideReversalAction
{
    public function __construct(
        private readonly ReversalExecutorRegistry $executors,
        private readonly AuditLogger $audit,
    ) {}

    public function approve(ReversalRequest $request, User $approver): ReversalRequest
    {
        if (! $request->isPending()) {
            throw ReversalRefusedException::notPending();
        }

        if ($request->requested_by === $approver->getKey()) {
            throw ReversalRefusedException::selfApproval();
        }

        return DB::transaction(function () use ($request, $approver): ReversalRequest {
            /*
             * Lock the request itself first. Two approvers opening the same
             * queue and clicking at the same moment must not both run the
             * executor — the second waits here, then finds the row no longer
             * pending.
             */
            $locked = ReversalRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if (! $locked->isPending()) {
                throw ReversalRefusedException::notPending();
            }

            $executor = $this->executors->for($locked->reversal_type);

            $executor->guard($locked);

            $entry = $executor->execute($locked, $approver);

            $locked->update([
                'status' => ReversalStatus::Approved,
                'approved_by' => $approver->getKey(),
                'decided_at' => Date::now(),
                'reversal_entry_id' => $entry?->getKey(),
            ]);

            return $locked->fresh([
                'journalEntry', 'reversalEntry', 'payment', 'disbursementBatch', 'loanSchedule', 'advancePayment', 'loan',
            ]);
        });
    }

    public function reject(ReversalRequest $request, ?string $note, User $approver): ReversalRequest
    {
        if (! $request->isPending()) {
            throw ReversalRefusedException::notPending();
        }

        /*
         * Rejecting one's own request is allowed, and is how a requester
         * withdraws. Nothing moves, so the control that stops self-approval
         * has nothing to protect here.
         */
        return DB::transaction(function () use ($request, $note, $approver): ReversalRequest {
            $locked = ReversalRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if (! $locked->isPending()) {
                throw ReversalRefusedException::notPending();
            }

            $locked->update([
                'status' => ReversalStatus::Rejected,
                'approved_by' => $approver->getKey(),
                'decided_at' => Date::now(),
                'decision_note' => $note,
            ]);

            $this->audit->log(
                AuditAction::ReversalRejected,
                $locked,
                after: [
                    'type' => $locked->reversal_type->value,
                    'note' => $note,
                    'withdrawn' => $locked->requested_by === $approver->getKey(),
                ],
                actor: $approver,
            );

            return $locked->fresh(['journalEntry', 'payment', 'disbursementBatch', 'loanSchedule', 'advancePayment', 'loan']);
        });
    }
}

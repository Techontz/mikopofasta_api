<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Actions;

use App\Domain\Ledger\Enums\ReversalStatus;
use App\Domain\Ledger\Exceptions\ReversalNotPermittedException;
use App\Domain\Ledger\Services\LedgerService;
use App\Enums\AuditAction;
use App\Models\JournalEntry;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Ledger reversal — §5 and §15.4.
 *
 * Two steps by design, because §14 makes requesting and approving different
 * permissions held by different roles: a Branch Manager can request
 * (`ledger.reverse.request`), only Finance or Super Admin can approve
 * (`ledger.reverse.approve`).
 *
 * Approval posts a NEW mirrored entry through LedgerService. Nothing is ever
 * edited or deleted — §5: "The original entry's lines are untouched — this is
 * what makes the ledger auditable end-to-end."
 */
final class ReverseJournalEntryAction
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function request(JournalEntry $entry, string $reason, User $requester): ReversalRequest
    {
        $this->guardReversible($entry);

        if (ReversalRequest::query()
            ->where('journal_entry_id', $entry->getKey())
            ->where('status', ReversalStatus::Pending)
            ->exists()) {
            throw ReversalNotPermittedException::alreadyRequested($entry->entry_number);
        }

        return DB::transaction(function () use ($entry, $reason, $requester): ReversalRequest {
            $request = ReversalRequest::query()->create([
                'journal_entry_id' => $entry->getKey(),
                'requested_by' => $requester->getKey(),
                'reason' => $reason,
                'status' => ReversalStatus::Pending,
            ]);

            $this->audit->log(
                AuditAction::ReversalRequested,
                $entry,
                after: ['reason' => $reason, 'request_id' => $request->getKey()],
                actor: $requester,
            );

            return $request;
        });
    }

    public function approve(ReversalRequest $request, User $approver): ReversalRequest
    {
        if (! $request->isPending()) {
            throw ReversalNotPermittedException::notPending();
        }

        /*
         * §14's separation of duties: whoever asked for the reversal cannot be
         * the one who waves it through. The same rule as loan approval, and
         * for the same reason — a single person must not be able to move money
         * and bless the movement.
         */
        if ($request->requested_by === $approver->getKey()) {
            throw ReversalNotPermittedException::selfApproval();
        }

        $entry = $request->journalEntry;

        $this->guardReversible($entry);

        return DB::transaction(function () use ($request, $entry, $approver): ReversalRequest {
            $reversal = $this->ledger->reverse($entry, $request->reason, $approver);

            $request->update([
                'status' => ReversalStatus::Approved,
                'approved_by' => $approver->getKey(),
                'decided_at' => Date::now(),
                'reversal_entry_id' => $reversal->getKey(),
            ]);

            $this->audit->log(
                AuditAction::LedgerEntryReversed,
                $entry,
                after: [
                    'reversal_entry' => $reversal->entry_number,
                    'approved_by' => $approver->getKey(),
                ],
                actor: $approver,
            );

            return $request->fresh(['reversalEntry']);
        });
    }

    public function reject(ReversalRequest $request, ?string $note, User $approver): ReversalRequest
    {
        if (! $request->isPending()) {
            throw ReversalNotPermittedException::notPending();
        }

        return DB::transaction(function () use ($request, $note, $approver): ReversalRequest {
            $request->update([
                'status' => ReversalStatus::Rejected,
                'approved_by' => $approver->getKey(),
                'decided_at' => Date::now(),
                'decision_note' => $note,
            ]);

            $this->audit->log(
                AuditAction::ReversalRejected,
                $request->journalEntry,
                after: ['note' => $note],
                actor: $approver,
            );

            return $request->fresh();
        });
    }

    private function guardReversible(JournalEntry $entry): void
    {
        // Reversing a reversal would be an endless chain; correcting a bad
        // reversal means posting a fresh corrective entry instead.
        if ($entry->is_reversal) {
            throw ReversalNotPermittedException::isReversal();
        }

        if ($entry->hasBeenReversed()) {
            throw ReversalNotPermittedException::alreadyReversed($entry->entry_number);
        }
    }
}

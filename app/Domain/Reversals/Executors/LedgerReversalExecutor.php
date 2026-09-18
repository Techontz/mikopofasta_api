<?php

declare(strict_types=1);

namespace App\Domain\Reversals\Executors;

use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Reversals\Enums\ReversalType;
use App\Domain\Reversals\Exceptions\ReversalRefusedException;
use App\Enums\AuditAction;
use App\Models\JournalEntry;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;

/**
 * A bare journal entry, with no workflow of its own behind it.
 *
 * This is the original reversal behaviour, unchanged: mirror the entry, touch
 * nothing else. It remains correct for the postings that ARE only postings —
 * an expense, a float transfer, a payroll run — where there is no schedule and
 * no loan whose standing could be wrong afterwards.
 *
 * It is deliberately NOT the fallback for a payment or a disbursement. Those
 * have their own executors, and routing them here would mirror the entry and
 * leave the loan claiming money it no longer has.
 */
final class LedgerReversalExecutor implements ReversalExecutor
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function type(): ReversalType
    {
        return ReversalType::Ledger;
    }

    public function guard(ReversalRequest $request): void
    {
        $entry = $this->entry($request);

        // Reversing a reversal would be an endless chain; correcting a bad
        // reversal means posting a fresh corrective entry instead.
        if ($entry->is_reversal) {
            throw ReversalRefusedException::entryIsReversal();
        }

        if ($entry->hasBeenReversed()) {
            throw ReversalRefusedException::entryAlreadyReversed($entry->entry_number);
        }
    }

    public function amount(ReversalRequest $request): Money
    {
        $entry = $this->entry($request)->loadMissing('lines');

        return Money::sum($entry->lines->map(fn ($line): Money => $line->debitAmount()));
    }

    public function describe(ReversalRequest $request): string
    {
        return sprintf('Entry %s', $this->entry($request)->entry_number);
    }

    public function execute(ReversalRequest $request, User $approver): ?JournalEntry
    {
        $entry = $this->entry($request);

        $this->guard($request);

        $reversal = $this->ledger->reverse($entry, $request->reason, $approver);

        $this->audit->log(
            AuditAction::LedgerEntryReversed,
            $entry,
            after: [
                'reversal_request' => $request->getKey(),
                'reversal_entry' => $reversal->entry_number,
                'approved_by' => $approver->getKey(),
            ],
            actor: $approver,
        );

        return $reversal;
    }

    private function entry(ReversalRequest $request): JournalEntry
    {
        $entry = $request->journalEntry;

        if ($entry === null) {
            throw ReversalRefusedException::subjectMissing('journal entry');
        }

        return $entry;
    }
}

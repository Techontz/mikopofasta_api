<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Actions;

use App\Domain\Ledger\Services\LedgerService;
use App\Enums\AuditAction;
use App\Models\CapitalContribution;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Removes a capital contribution (DELETE /capital-contributions/{contribution}).
 *
 * The posted entry is NOT deleted — §5 makes that architecturally impossible.
 * A reversing entry is posted instead, so the ledger still shows the money
 * arriving and then leaving, and the trial balance stays true. The row itself
 * is soft-deleted so the screen stops listing it.
 */
final class DeleteCapitalAction
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(CapitalContribution $contribution, User $actor): void
    {
        DB::transaction(function () use ($contribution, $actor): void {
            $entry = $contribution->journalEntry;

            if ($entry !== null) {
                $this->ledger->reverse(
                    $entry,
                    sprintf('Capital contribution #%d removed', $contribution->id),
                    $actor,
                );
            }

            $this->audit->log(
                AuditAction::CapitalDeleted,
                $contribution,
                before: [
                    'shareholder_id' => $contribution->shareholder_id,
                    'amount' => $contribution->amount,
                    'journal_entry_id' => $contribution->journal_entry_id,
                ],
                actor: $actor,
            );

            $contribution->delete();
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Actions;

use App\Domain\Expenses\Exceptions\ExpenseException;
use App\Enums\AuditAction;
use App\Models\ExpenseRequest;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Withdraws a request that has not been decided.
 *
 * §2's no-delete rule applies to money, and a pending request is not money —
 * nothing has posted, so removing the row removes nothing from the ledger. Once
 * it has been approved it has posted, and from that point the only way back is
 * a reversal; this refuses rather than soft-deleting a row whose journal entry
 * would then be stranded with no visible source.
 */
final class DeleteExpenseRequestAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(ExpenseRequest $request, User $actor): void
    {
        if ($request->journal_entry_id !== null) {
            throw ExpenseException::alreadyPosted();
        }

        if (! $request->status->isDecidable()) {
            throw ExpenseException::notPending();
        }

        $this->audit->log(
            AuditAction::ExpenseWithdrawn,
            $request,
            before: [
                'reference' => $request->reference,
                'amount' => $request->amount,
                'description' => $request->description,
            ],
            actor: $actor,
        );

        $request->delete();
    }
}

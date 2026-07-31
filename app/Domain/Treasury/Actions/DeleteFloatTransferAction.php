<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Actions;

use App\Domain\Treasury\Enums\FloatTransferStatus;
use App\Domain\Treasury\Exceptions\FloatTransferInvalidException;
use App\Enums\AuditAction;
use App\Models\FloatTransfer;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Removes a float transfer (DELETE /float-transfers/{transfer}).
 *
 * Only while it is still pending. Once approved it has moved money, and money
 * that has moved is undone by a ledger reversal, not by deleting the record
 * that explains it — the legacy screen offers delete only on the pending list
 * for the same reason.
 */
final class DeleteFloatTransferAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(FloatTransfer $transfer, User $actor): void
    {
        if ($transfer->status !== FloatTransferStatus::Pending || $transfer->journal_entry_id !== null) {
            throw FloatTransferInvalidException::alreadyPosted();
        }

        DB::transaction(function () use ($transfer, $actor): void {
            $this->audit->log(
                AuditAction::FloatTransferDeleted,
                $transfer,
                before: ['kind' => $transfer->kind->value, 'amount' => $transfer->amount],
                actor: $actor,
            );

            $transfer->delete();
        });
    }
}

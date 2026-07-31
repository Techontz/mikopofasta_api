<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Services;

use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Services\LedgerService;
use App\Models\FloatTransfer;
use App\Models\User;
use App\Support\Money;

/**
 * Posts a float transfer to the ledger.
 *
 * Both sides are assets, so a transfer nets to nothing on the P&L — it only
 * changes where the company's cash sits:
 *
 *     Dr  destination account
 *     Cr  source account
 *
 * Called at two different moments depending on the kind: immediately for
 * company→branch and account→account, and only on approval for branch→branch.
 * Keeping it in one place is what makes those two paths post identically.
 */
final class FloatPoster
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function post(FloatTransfer $transfer, User $actor): FloatTransfer
    {
        $amount = Money::of($transfer->amount);

        $entry = $this->ledger->post(
            $this->describe($transfer),
            JournalSourceType::Transfer,
            $transfer->id,
            [
                JournalLine::debit($transfer->to_account_id, $amount, branchId: $transfer->to_branch_id),
                JournalLine::credit($transfer->from_account_id, $amount, branchId: $transfer->from_branch_id),
            ],
            $actor,
        );

        $transfer->forceFill(['journal_entry_id' => $entry->id])->save();

        return $transfer;
    }

    private function describe(FloatTransfer $transfer): string
    {
        $transfer->loadMissing(['fromBranch', 'toBranch', 'fromAccount', 'toAccount']);

        $from = $transfer->fromBranch?->name ?? $transfer->fromAccount?->name ?? 'source';
        $to = $transfer->toBranch?->name ?? $transfer->toAccount?->name ?? 'destination';

        return sprintf('Float transfer %s to %s', $from, $to);
    }
}

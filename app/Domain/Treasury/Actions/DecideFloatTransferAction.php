<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Actions;

use App\Domain\Treasury\Enums\FloatTransferStatus;
use App\Domain\Treasury\Exceptions\FloatTransferInvalidException;
use App\Domain\Treasury\Services\FloatPoster;
use App\Enums\AuditAction;
use App\Models\FloatTransfer;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Approves or rejects a pending branch-to-branch transfer.
 *
 * Approval is the moment money moves — the posting happens here, not when the
 * transfer was raised. A rejection posts nothing at all, so a refused request
 * leaves no trace in the trial balance.
 *
 * That the approver is not the requester is enforced by CapitalPolicy::decide()
 * before this runs (§14).
 */
final class DecideFloatTransferAction
{
    public function __construct(
        private readonly FloatPoster $poster,
        private readonly AuditLogger $audit,
    ) {}

    public function approve(FloatTransfer $transfer, User $actor): FloatTransfer
    {
        return DB::transaction(function () use ($transfer, $actor): FloatTransfer {
            $this->assertPending($transfer);

            $transfer->forceFill([
                'status' => FloatTransferStatus::Approved,
                'approved_by' => $actor->getKey(),
                'approved_at' => Date::now(),
            ])->save();

            $this->poster->post($transfer, $actor);

            $this->audit->log(
                AuditAction::FloatTransferApproved,
                $transfer,
                after: ['amount' => $transfer->amount, 'journal_entry_id' => $transfer->journal_entry_id],
                actor: $actor,
            );

            return $transfer->fresh(['fromBranch', 'toBranch', 'requester', 'approver']);
        });
    }

    public function reject(FloatTransfer $transfer, string $reason, User $actor): FloatTransfer
    {
        return DB::transaction(function () use ($transfer, $reason, $actor): FloatTransfer {
            $this->assertPending($transfer);

            $transfer->forceFill([
                'status' => FloatTransferStatus::Rejected,
                'approved_by' => $actor->getKey(),
                'approved_at' => Date::now(),
                'rejection_reason' => $reason,
            ])->save();

            $this->audit->log(
                AuditAction::FloatTransferRejected,
                $transfer,
                after: ['reason' => $reason],
                actor: $actor,
            );

            return $transfer->fresh(['fromBranch', 'toBranch', 'requester', 'approver']);
        });
    }

    private function assertPending(FloatTransfer $transfer): void
    {
        if ($transfer->status !== FloatTransferStatus::Pending) {
            throw FloatTransferInvalidException::notPending();
        }
    }
}

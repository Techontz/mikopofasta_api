<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Actions;

use App\Domain\Treasury\DTOs\FloatTransferData;
use App\Domain\Treasury\Enums\FloatTransferKind;
use App\Domain\Treasury\Enums\FloatTransferStatus;
use App\Domain\Treasury\Services\FloatAccountResolver;
use App\Domain\Treasury\Services\FloatPoster;
use App\Enums\AuditAction;
use App\Models\FloatTransfer;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Raises a float transfer (POST /float-transfers).
 *
 * Branch → branch is raised `pending` and posts nothing: money moves when a
 * second person approves it (§14). The other two kinds are one person moving
 * the company's own money between its own accounts, so they apply at once —
 * which is why the legacy screens show no status for either.
 */
final class RequestFloatTransferAction
{
    public function __construct(
        private readonly FloatAccountResolver $resolver,
        private readonly FloatPoster $poster,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(FloatTransferData $data, User $actor): FloatTransfer
    {
        return DB::transaction(function () use ($data, $actor): FloatTransfer {
            [$from, $to] = $this->resolver->resolve($data);

            $immediate = ! $data->kind->requiresApproval();

            // Company float always leaves head office, whether or not the
            // caller named it.
            $fromBranchId = $data->kind === FloatTransferKind::CompanyToBranch
                ? $this->resolver->headOffice()->id
                : $data->fromBranchId;

            $transfer = FloatTransfer::query()->create([
                'kind' => $data->kind,
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $data->toBranchId,
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => $data->amount,
                'status' => $immediate ? FloatTransferStatus::Approved : FloatTransferStatus::Pending,
                'requested_by' => $actor->getKey(),
                'approved_by' => $immediate ? $actor->getKey() : null,
                'approved_at' => $immediate ? Date::now() : null,
            ]);

            if ($immediate) {
                $this->poster->post($transfer, $actor);
            }

            $this->audit->log(
                AuditAction::FloatTransferRequested,
                $transfer,
                after: [
                    'kind' => $data->kind->value,
                    'amount' => $transfer->amount,
                    'from_account_id' => $from->id,
                    'to_account_id' => $to->id,
                    'status' => $transfer->status->value,
                ],
                actor: $actor,
            );

            return $transfer->fresh(['fromBranch', 'toBranch', 'fromAccount', 'toAccount', 'requester']);
        });
    }
}

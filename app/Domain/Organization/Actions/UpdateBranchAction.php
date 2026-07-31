<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\DTOs\BranchData;
use App\Domain\Organization\Exceptions\BranchCycleException;
use App\Domain\Organization\Services\BranchHierarchy;
use App\Domain\Organization\Support\BranchSnapshot;
use App\Enums\AuditAction;
use App\Models\Branch;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Updates a branch (PUT /branches/{branch}).
 */
final class UpdateBranchAction
{
    public function __construct(
        private readonly BranchHierarchy $hierarchy,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Branch $branch, BranchData $data, User $actor): Branch
    {
        /*
         * Re-parenting is the one edit that can corrupt the tree: pointing a
         * branch at one of its own descendants closes a loop, and every later
         * ancestor/descendant walk would then run forever.
         */
        if ($this->hierarchy->wouldCreateCycle($branch, $data->parentBranchId)) {
            throw new BranchCycleException;
        }

        return DB::transaction(function () use ($branch, $data, $actor): Branch {
            $before = BranchSnapshot::of($branch);

            $branch->update([
                'name' => $data->name,
                'region_id' => $data->regionId,
                'zone_id' => $data->zoneId,
                'phone' => $data->phone,
                'type' => $data->type,
                'parent_branch_id' => $data->parentBranchId,
                'status' => $data->status,
            ]);

            $this->audit->log(
                AuditAction::BranchUpdated,
                $branch,
                before: $before,
                after: BranchSnapshot::of($branch->refresh()),
                actor: $actor,
            );

            return $branch->load(['region', 'zone', 'parent']);
        });
    }
}

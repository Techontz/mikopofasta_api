<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\DTOs\BranchData;
use App\Domain\Organization\Services\BranchCodeGenerator;
use App\Domain\Organization\Support\BranchSnapshot;
use App\Enums\AuditAction;
use App\Models\Branch;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Creates a branch (POST /branches).
 *
 * `is_head_office` is always false here — the frontend hardcodes it and HQ is
 * moved only through SetHeadOfficeAction, which demotes the incumbent in the
 * same transaction. Allowing it on create would make two head offices
 * expressible in one call.
 *
 * No cycle check is needed: a brand-new branch has no descendants, so any
 * existing branch is a valid parent.
 */
final class CreateBranchAction
{
    public function __construct(
        private readonly BranchCodeGenerator $codes,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(BranchData $data, User $actor): Branch
    {
        return DB::transaction(function () use ($data, $actor): Branch {
            $branch = Branch::query()->create([
                'name' => $data->name,
                /*
                 * Derived when the caller did not choose one. The code is a
                 * segment of every customer payment reference this branch will
                 * issue, so it cannot be left empty — but refusing the request
                 * over it would be worse than deriving something an
                 * administrator can correct.
                 */
                'code' => $data->code ?? $this->codes->forName($data->name),
                'region_id' => $data->regionId,
                'zone_id' => $data->zoneId,
                'phone' => $data->phone,
                'type' => $data->type,
                'parent_branch_id' => $data->parentBranchId,
                'is_head_office' => false,
                'status' => $data->status,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->log(
                AuditAction::BranchCreated,
                $branch,
                after: BranchSnapshot::of($branch),
                actor: $actor,
            );

            return $branch->load(['region', 'zone', 'parent']);
        });
    }
}

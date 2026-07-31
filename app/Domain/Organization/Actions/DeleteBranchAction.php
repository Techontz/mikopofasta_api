<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Exceptions\HeadOfficeProtectedException;
use App\Domain\Organization\Exceptions\OrganizationInUseException;
use App\Domain\Organization\Support\BranchSnapshot;
use App\Enums\AuditAction;
use App\Models\Branch;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Soft-deletes a branch (DELETE /branches/{branch}).
 *
 * The frontend refuses deletion when the branch is Head Office, or when it has
 * customers or loans on record. The first two guards below implement what is
 * checkable today; `customers` and `loans` do not exist until Phases 4–5, and
 * their guards are added with those tables — the FK is RESTRICT either way, so
 * the database is the backstop until then.
 *
 * Users and sub-branches are additional guards, not invented rules: both
 * columns are RESTRICT on delete (spec §2), so without them the request would
 * surface as a 500 from an integrity-constraint violation instead of a 409
 * that says what is in the way.
 */
final class DeleteBranchAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Branch $branch, User $actor): void
    {
        if ($branch->isHeadOffice()) {
            throw new HeadOfficeProtectedException;
        }

        $children = $branch->children()->count();

        if ($children > 0) {
            throw OrganizationInUseException::branchHasChildren($children);
        }

        $users = $branch->users()->count();

        if ($users > 0) {
            throw OrganizationInUseException::branchHasUsers($users);
        }

        DB::transaction(function () use ($branch, $actor): void {
            $this->audit->log(
                AuditAction::BranchDeleted,
                $branch,
                before: BranchSnapshot::of($branch),
                actor: $actor,
            );

            $branch->delete();
        });
    }
}

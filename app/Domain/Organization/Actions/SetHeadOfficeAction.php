<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Enums\AuditAction;
use App\Models\Branch;
use App\Models\CompanyProfile;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Promotes a branch to Head Office (POST /branches/{branch}/head-office).
 *
 * Spec §2.2 notes that "at most one row TRUE" cannot be enforced natively by
 * MySQL and is an application-layer invariant. This action is where that
 * invariant lives: demotion of the incumbent and promotion of the target
 * happen in one transaction, so there is no window in which the system has two
 * head offices or none.
 *
 * The company profile's `headquarters_branch_id` is moved at the same time.
 * Leaving it pointing at the former HQ would make the Company Profile screen
 * disagree with the branches table about which branch is head office.
 */
final class SetHeadOfficeAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Branch $branch, User $actor): Branch
    {
        return DB::transaction(function () use ($branch, $actor): Branch {
            $previous = Branch::query()
                ->where('is_head_office', true)
                ->whereKeyNot($branch->getKey())
                ->get();

            foreach ($previous as $formerHeadOffice) {
                $formerHeadOffice->update(['is_head_office' => false]);
            }

            $branch->update(['is_head_office' => true]);

            $profile = CompanyProfile::query()->first();
            $profile?->update(['headquarters_branch_id' => $branch->getKey()]);

            $this->audit->log(
                AuditAction::HeadOfficeChanged,
                $branch,
                before: ['head_office_branch_ids' => $previous->pluck('id')->all()],
                after: ['head_office_branch_id' => $branch->getKey()],
                actor: $actor,
            );

            return $branch->load(['region', 'zone', 'parent']);
        });
    }
}

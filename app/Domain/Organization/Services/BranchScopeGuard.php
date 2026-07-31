<?php

declare(strict_types=1);

namespace App\Domain\Organization\Services;

use App\Domain\Organization\Exceptions\BranchScopeViolationException;
use App\Enums\AuditAction;
use App\Models\Branch;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Enforces §13 branch scoping on a single record, and records the attempt.
 *
 * The spec is explicit that a scope violation is not merely refused but
 * *logged*: "Attempting to access another branch's record ... returns 403 with
 * error_code: BRANCH_SCOPE_VIOLATION, logged to audit_logs — cross-branch
 * snooping attempts are themselves an auditable event."
 *
 * Later modules call this with their own branch id (a customer's, a loan's)
 * rather than restating the check.
 */
final class BranchScopeGuard
{
    public function __construct(
        private readonly BranchScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Throws — and audits — if the user may not reach this branch.
     */
    public function authorize(User $user, Branch $branch): void
    {
        if ($this->scope->canSee($user, $branch)) {
            return;
        }

        $this->deny($user, $branch->getKey(), Branch::class);
    }

    /**
     * The same check against a bare branch id, for records that merely carry
     * one (a customer, a loan, a payment).
     */
    public function authorizeBranchId(User $user, ?int $branchId, string $subjectType): void
    {
        if ($branchId === null) {
            return;
        }

        if (in_array($branchId, $this->scope->visibleBranchIds($user), true)) {
            return;
        }

        $this->deny($user, $branchId, $subjectType);
    }

    private function deny(User $user, int $branchId, string $subjectType): never
    {
        $this->audit->logAnonymous(
            AuditAction::BranchScopeViolation,
            $subjectType,
            (string) $user->getKey(),
            [
                'attempted_branch_id' => $branchId,
                'user_branch_id' => $user->branch_id,
                'user_zone_id' => $user->zone_id,
                'user_region_id' => $user->region_id,
            ],
        );

        throw new BranchScopeViolationException;
    }
}

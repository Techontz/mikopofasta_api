<?php

declare(strict_types=1);

namespace App\Domain\Organization\Services;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves which branches a user may see — backend spec §13.
 *
 * ## Reconciling the two documents
 *
 * §13 says "every role is branch-scoped unless explicitly granted otherwise",
 * and resolves the wider scopes by role: Zone Manager sees
 * `branches.zone_id = user.zone_id`, Regional Manager sees
 * `branches.region_id = user.region_id`, HQ roles see everything.
 *
 * The frontend expresses the same idea through a single permission: its
 * BranchSwitcher shows a branch picker when the user holds `branches.view_all`
 * and a fixed home-branch label otherwise. It grants `branches.view_all` to
 * super_admin, admin, finance, auditor, zone_manager and regional_manager.
 *
 * Taken alone, the frontend permission would give a Zone Manager sight of
 * every branch in the country, which §13 explicitly does not. Taken alone, §13
 * has no gate at all. This class combines them the only way that satisfies
 * both:
 *
 *   `branches.view_all` decides WHETHER the user sees beyond their own branch;
 *   the user's zone/region assignment decides HOW FAR that reaches.
 *
 *   no permission              → own branch only
 *   permission + zone_id       → every branch in that zone
 *   permission + region_id     → every branch in that region
 *   permission, neither set    → every branch (HQ roles)
 *
 * Note what this class is NOT: it is not authority to act. §13 is emphatic
 * that cross-branch *loan review* requires the separate, explicit
 * `loans.review_cross_branch` grant and is never implied by scope. This
 * resolves visibility only.
 */
final class BranchScope
{
    /**
     * Constrains a branch query to what the user may see.
     *
     * @param Builder<Branch> $query
     * @return Builder<Branch>
     */
    public function apply(Builder $query, User $user): Builder
    {
        if ($this->seesAllBranches($user)) {
            return $query;
        }

        return $query->whereIn('id', $this->visibleBranchIds($user));
    }

    /**
     * Constrains any branch-scoped table (customers, loans, payments, journal
     * lines) to the user's visible branches. Later modules apply this to their
     * own queries rather than restating the rule.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param Builder<TModel> $query
     * @return Builder<TModel>
     */
    public function applyToColumn(Builder $query, User $user, string $column = 'branch_id'): Builder
    {
        if ($this->seesAllBranches($user)) {
            return $query;
        }

        return $query->whereIn($column, $this->visibleBranchIds($user));
    }

    /**
     * The concrete branch ids this user may see.
     *
     * A branch's sub-branches come along with it: a sub-branch rolls up into
     * its parent for reporting (§12), so someone who can see the parent but
     * not its children would read incomplete totals.
     *
     * @return list<int>
     */
    public function visibleBranchIds(User $user): array
    {
        if ($this->seesAllBranches($user)) {
            return Branch::query()->pluck('id')->all();
        }

        $query = Branch::query();

        if ($user->hasPermission(PermissionName::BranchesViewAll)) {
            if ($user->zone_id !== null) {
                return $query->where('zone_id', $user->zone_id)->pluck('id')->all();
            }

            if ($user->region_id !== null) {
                return $query->where('region_id', $user->region_id)->pluck('id')->all();
            }
        }

        if ($user->branch_id === null) {
            return [];
        }

        $home = Branch::query()->find($user->branch_id);

        return $home === null ? [] : $home->selfAndDescendantIds();
    }

    /**
     * True for HQ roles: they hold `branches.view_all` and are pinned to
     * neither a zone nor a region, so nothing narrows them.
     */
    public function seesAllBranches(User $user): bool
    {
        return $user->hasPermission(PermissionName::BranchesViewAll)
            && $user->zone_id === null
            && $user->region_id === null;
    }

    public function canSee(User $user, Branch $branch): bool
    {
        return $this->seesAllBranches($user)
            || in_array($branch->id, $this->visibleBranchIds($user), true);
    }
}

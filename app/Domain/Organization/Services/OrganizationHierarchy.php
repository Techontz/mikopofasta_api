<?php

declare(strict_types=1);

namespace App\Domain\Organization\Services;

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Organization\Enums\OrganizationTier;
use App\Models\Branch;
use App\Models\User;
use App\Models\Zone;

/**
 * Where somebody sits, and who reports to whom.
 *
 *     SUPER ADMIN  →  HEAD OFFICE  →  ZONES  →  BRANCHES
 *
 * One place answers both questions, for the same reason BranchScope is one
 * place: a hierarchy re-derived per screen is a hierarchy that disagrees with
 * itself the first time somebody is posted somewhere unusual.
 *
 * ## Tier is read from the POSTING, not from the role
 *
 * A Head Office Teller and a branch Teller hold the identical role. What makes
 * one of them Head Office is `users.branch_id` pointing at the branch flagged
 * `is_head_office` — which is the fact the client's own structure turns on, and
 * the fact BranchScope already uses to decide what they can see.
 *
 * Deriving it from role names instead would mean minting `ho_teller`,
 * `ho_cashier`, `ho_accountant` and so on: the office recorded twice, in the
 * role and in the posting, free to contradict each other the day somebody
 * transfers.
 *
 * Super Admin and System are the two exceptions, and both are genuinely roles
 * rather than places — one governs the institution from no office, the other is
 * not a person.
 *
 * ## What this does NOT decide
 *
 * Authority. `isOperational()` chooses a dashboard; it never authorises
 * anything. Permissions remain the only gate, which is why a Head Office
 * Cashier and a Head Office Credit Officer share a tier and almost nothing else.
 */
final class OrganizationHierarchy
{
    public function __construct(private readonly BranchScope $scope) {}

    public function tierFor(User $user): OrganizationTier
    {
        $role = $user->roleName();

        if ($role === RoleName::SuperAdmin) {
            return OrganizationTier::SuperAdmin;
        }

        if ($role === RoleName::System) {
            return OrganizationTier::System;
        }

        /*
         * Zone before Head Office: a Zone Manager is pinned to a zone, and
         * being pinned is precisely what stops them seeing the whole book.
         * BranchScope makes the same call in the same order.
         */
        if ($user->zone_id !== null || $user->region_id !== null) {
            return OrganizationTier::Zone;
        }

        if ($this->isHeadOfficeUser($user)) {
            return OrganizationTier::HeadOffice;
        }

        return OrganizationTier::Branch;
    }

    /**
     * Posted to the Head Office, or to no branch at all while seeing every one.
     *
     * The second case covers the institution-wide roles that were never given a
     * home branch — Finance and Auditor among them. They report on the whole
     * book, which is what Head Office means, so calling them Branch would land
     * them on a dashboard scoped to a branch they do not have.
     */
    public function isHeadOfficeUser(User $user): bool
    {
        if ($user->branch_id === null) {
            return $this->scope->seesAllBranches($user);
        }

        return Branch::query()->whereKey($user->branch_id)->where('is_head_office', true)->exists();
    }

    /** The Head Office itself — §12 Decision 2 allows exactly one. */
    public function headOffice(): ?Branch
    {
        return Branch::query()->where('is_head_office', true)->first();
    }

    /**
     * The reporting line above a branch: its parent branch, then its zone, then
     * the Head Office.
     *
     * Returned as a list rather than a single answer because a sub-branch
     * genuinely has two supervisors above it — the branch it rolls into, and
     * the zone that branch belongs to — and a report that showed only one would
     * lose a tier of the structure.
     *
     * @return list<array{tier: string, id: string|null, name: string}>
     */
    public function reportingLineFor(Branch $branch): array
    {
        $line = [];

        $branch->loadMissing(['parent', 'zone']);

        if ($branch->parent !== null) {
            $line[] = [
                'tier' => OrganizationTier::Branch->value,
                'id' => (string) $branch->parent->getKey(),
                'name' => $branch->parent->name,
            ];
        }

        if ($branch->zone !== null) {
            $line[] = [
                'tier' => OrganizationTier::Zone->value,
                'id' => (string) $branch->zone->getKey(),
                'name' => $branch->zone->name,
            ];
        }

        $headOffice = $this->headOffice();

        if ($headOffice !== null && $headOffice->getKey() !== $branch->getKey()) {
            $line[] = [
                'tier' => OrganizationTier::HeadOffice->value,
                'id' => (string) $headOffice->getKey(),
                'name' => $headOffice->name,
            ];
        }

        return $line;
    }

    /**
     * The branches a zone supervises.
     *
     * @return list<int>
     */
    public function branchIdsInZone(int $zoneId): array
    {
        return Branch::query()->where('zone_id', $zoneId)->pluck('id')
            ->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * The whole structure, one query per tier, for the Super Admin console.
     *
     * Eager-loaded rather than walked: an institution with sixty branches would
     * otherwise cost sixty queries to draw a tree that is read constantly and
     * changes almost never.
     *
     * @return array<string, mixed>
     */
    public function structure(): array
    {
        $branches = Branch::query()->orderBy('name')->get(['id', 'name', 'zone_id', 'parent_branch_id', 'is_head_office']);
        $zones = Zone::query()->orderBy('name')->get(['id', 'name']);

        $byZone = $branches->groupBy('zone_id');

        return [
            'headOffice' => $this->describeBranch($branches->firstWhere('is_head_office', true)),
            'zones' => $zones->map(fn (Zone $zone): array => [
                'id' => (string) $zone->getKey(),
                'name' => $zone->name,
                'branchCount' => $byZone->get($zone->getKey(), collect())->count(),
                'branches' => $byZone->get($zone->getKey(), collect())
                    ->map(fn (Branch $b): array => $this->describeBranch($b))->values()->all(),
            ])->values()->all(),
            /*
             * Surfaced, not hidden. A branch belonging to no zone is a real
             * configuration state and usually an oversight — it falls outside
             * every Zone Manager's scope, so nobody supervises it.
             */
            'unzonedBranches' => $byZone->get(null, collect())
                ->reject(fn (Branch $b): bool => (bool) $b->is_head_office)
                ->map(fn (Branch $b): array => $this->describeBranch($b))->values()->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function describeBranch(?Branch $branch): ?array
    {
        if ($branch === null) {
            return null;
        }

        return [
            'id' => (string) $branch->getKey(),
            'name' => $branch->name,
            'isHeadOffice' => (bool) $branch->is_head_office,
            'parentBranchId' => $branch->parent_branch_id === null ? null : (string) $branch->parent_branch_id,
        ];
    }
}

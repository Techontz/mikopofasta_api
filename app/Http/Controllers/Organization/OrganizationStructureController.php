<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Domain\Auth\Enums\PermissionName;
use App\Domain\Auth\Enums\RoleName;
use App\Domain\Organization\Enums\OrganizationTier;
use App\Domain\Organization\Services\BranchScope;
use App\Domain\Organization\Services\OrganizationHierarchy;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The organisational structure, and where the caller stands in it.
 *
 *     SUPER ADMIN  →  HEAD OFFICE  →  ZONES  →  BRANCHES
 *
 * Two endpoints, deliberately separate:
 *
 *   GET /organization/structure   the whole institution — the Super Admin
 *                                 console's governance view.
 *   GET /organization/me          where the caller sits, what they can see, and
 *                                 who supervises them. Every tier may ask this
 *                                 about themselves.
 *
 * ## Why the console is data, not a new module
 *
 * The client asked for a Super Admin interface that manages the institution,
 * capital, shareholders, reserve settings, configuration, branches, zones,
 * products, roles, permissions and master data. Every one of those already has
 * a module and a screen. Rebuilding them behind a second set of routes would be
 * exactly the duplication the standing instruction forbids — two places to
 * change a product, free to disagree.
 *
 * So the console is a governance SURFACE over the modules that exist: this
 * endpoint gives it the structure and the counts it needs to orient somebody,
 * and every action it offers links to the module that already owns it.
 */
final class OrganizationStructureController extends Controller
{
    public function __construct(
        private readonly OrganizationHierarchy $hierarchy,
        private readonly BranchScope $scope,
    ) {}

    /**
     * GET /api/v1/organization/structure
     *
     * Gated on `admin.org_settings` — the grant that already governs writing to
     * the organisation. Reading the whole shape of the institution is the same
     * kind of act as changing it, and the tiers that need only their own slice
     * have `/organization/me`.
     */
    public function structure(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        abort_unless($actor->hasPermission(PermissionName::AdminOrgSettings), 403);

        $structure = $this->hierarchy->structure();

        return ApiResponse::data($structure + [
            'openLoans' => $this->openLoans(),
            'staffByTier' => $this->staffByTier(),
            'staffByRole' => $this->staffByRole(),
            'tiers' => array_map(static fn (OrganizationTier $t): array => [
                'value' => $t->value,
                'label' => $t->label(),
                'scope' => $t->scopeDescription(),
                'isOperational' => $t->isOperational(),
            ], OrganizationTier::cases()),
        ]);
    }

    /**
     * GET /api/v1/organization/me
     *
     * Where the caller sits and what that means for them. No permission beyond
     * being authenticated: a user asking about their own position is not
     * privileged information.
     */
    public function me(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $tier = $this->hierarchy->tierFor($actor);

        $branch = $actor->branch_id === null
            ? null
            : Branch::query()->with(['parent', 'zone'])->find($actor->branch_id);

        $visible = $this->scope->visibleBranchIds($actor);

        return ApiResponse::data([
            'tier' => $tier->value,
            'tierLabel' => $tier->label(),
            'scopeDescription' => $tier->scopeDescription(),
            'isOperational' => $tier->isOperational(),
            'branch' => $branch === null ? null : [
                'id' => (string) $branch->getKey(),
                'name' => $branch->name,
                'isHeadOffice' => (bool) $branch->is_head_office,
            ],
            'zoneId' => $actor->zone_id === null ? null : (string) $actor->zone_id,
            'regionId' => $actor->region_id === null ? null : (string) $actor->region_id,

            // What they actually supervise, from the same rule that scopes
            // every query they will make.
            'visibleBranchIds' => array_map(static fn (int $id): string => (string) $id, $visible),
            'visibleBranchCount' => count($visible),
            'reportsTo' => $branch === null ? [] : $this->hierarchy->reportingLineFor($branch),
        ]);
    }

    /**
     * Headcount per tier.
     *
     * Computed in PHP over one query rather than four grouped ones: the tier is
     * derived from a posting AND a role, so there is no column to group by —
     * and inventing one would be a second copy of the hierarchy that could
     * drift from OrganizationHierarchy.
     *
     * @return array<string, int>
     */
    private function staffByTier(): array
    {
        $counts = array_fill_keys(OrganizationTier::values(), 0);

        User::query()->with('role')->chunkById(500, function ($users) use (&$counts): void {
            foreach ($users as $user) {
                $counts[$this->hierarchy->tierFor($user)->value]++;
            }
        });

        return $counts;
    }

    /**
     * Headcount per role, including the roles nobody holds yet.
     *
     * The zeroes are the point: an institution with no Recovery Officer should
     * see that stated, not have the row quietly missing.
     *
     * @return list<array{role: string, label: string, count: int}>
     */
    private function staffByRole(): array
    {
        $counts = User::query()
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->groupBy('roles.name')
            ->selectRaw('roles.name as role_name, COUNT(*) as total')
            ->pluck('total', 'role_name');

        return array_map(static fn (RoleName $role): array => [
            'role' => $role->value,
            'label' => $role->label(),
            'count' => (int) ($counts[$role->value] ?? 0),
        ], RoleName::cases());
    }

    /**
     * How much of the book each tier is carrying — the one operational figure
     * the governance view needs, so a Super Admin can see the institution is
     * alive without leaving the console.
     */
    private function openLoans(): int
    {
        return Loan::query()->whereIn('status', ['active', 'arrears'])->count();
    }
}

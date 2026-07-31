<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Domain\Auth\Enums\PermissionName;
use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\Policies\ReportPolicy;
use App\Domain\Reports\Services\ReportRegistry;
use App\Enums\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRequest;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reporting — §15.6. Read-only, every one of them.
 *
 * There is no POST here and no report table anywhere in the schema. Every
 * report is recomputed from the operational tables and the ledger on each
 * call, which is what §15.6 means by "numbers on screen are traceable to a
 * specific computation timestamp" — and why `meta.generated_at` is the
 * timestamp of the computation rather than of a cache.
 *
 * One route serves all of them: §15.6 lists twenty-one paths of the form
 * `/reports/<name>`, and `/reports/{slug}` is exactly those paths. A separate
 * method per report would be twenty-four near-identical methods differing only
 * in which object they called.
 */
final class ReportController extends Controller
{
    public function __construct(private readonly ReportRegistry $registry) {}

    /**
     * GET /api/v1/reports — the catalogue.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize(ReportPolicy::VIEW_ABILITY);

        return ApiResponse::data($this->registry->catalogue(), [
            'generated_at' => Date::now()->toIso8601String(),
            'total' => count($this->registry->catalogue()),
        ]);
    }

    /**
     * GET /api/v1/reports/{slug}
     */
    public function show(ReportRequest $request, string $slug): JsonResponse
    {
        $this->authorize(ReportPolicy::VIEW_ABILITY);

        $report = $this->registry->find($slug);

        if ($report === null) {
            return ApiResponse::error(
                sprintf('No report exists at "%s".', $slug),
                ErrorCode::ResourceNotFound,
                Response::HTTP_NOT_FOUND,
            );
        }

        $filters = $this->resolveFilters($request, $report);
        $result = $report->compute($filters);

        return ApiResponse::data($result->rows, [
            /*
             * §15.6's two mandated meta keys, in §15.6's snake_case spelling.
             * The rest of the API emits camelCase attributes; these two are
             * quoted verbatim in the specification and are kept exactly as
             * written rather than silently renamed.
             */
            'generated_at' => Date::now()->toIso8601String(),
            'filters_applied' => (object) $filters->applied(),

            'report' => [
                'slug' => $report->slug(),
                'title' => $report->title(),
                'description' => $report->description(),
                'group' => $report->group(),
                'filters' => $report->supportedFilters(),
            ],
        ] + $result->toMeta());
    }

    /**
     * The filters a report will actually run under.
     *
     * Two narrowings, in order. First the report's own declaration — a
     * parameter it does not honour is dropped, so `filters_applied` never
     * claims a window the figures ignored. Then §13's branch scope: a user
     * without `branches.view_all` is pinned to their own branch regardless of
     * what the query string asked for, which is what stops a report being a
     * way around the scoping every other endpoint enforces.
     */
    private function resolveFilters(ReportRequest $request, Report $report): ReportFilters
    {
        $filters = ReportFilters::fromArray($request->validated())->only($report->supportedFilters());

        $actor = $this->actor($request);

        if ($actor->hasPermission(PermissionName::BranchesViewAll)) {
            return $filters;
        }

        // A report that does not slice by branch cannot be branch-scoped, so it
        // is left alone: the trial balance is the company's, not a branch's.
        if (! in_array('branchId', $report->supportedFilters(), true)) {
            return $filters;
        }

        return $filters->forBranch($actor->branch_id);
    }
}

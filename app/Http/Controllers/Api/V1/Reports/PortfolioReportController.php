<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Services\Reports\PortfolioReports;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Report tab → "Portfolio & Risk" (Documents: 🧠 OVERVIEW ALL REPORT). Requires reports.view; branch scoped.
 */
class PortfolioReportController extends ReportApiController
{
    public function __construct(private readonly PortfolioReports $reports) {}

    public function portfolio(Request $request): JsonResponse
    {
        $this->authorizeAny('reports.view');
        $scope = $this->reportScope($request);

        return response()->json(['data' => $this->reports->portfolio($scope) + ['filter' => $this->filterEcho($request, $scope)]]);
    }

    /**
     * Expected vs actual collection. Without dates the current month is reported.
     */
    public function collections(Request $request): JsonResponse
    {
        $this->authorizeAny('reports.view');
        $request->validate(['period' => ['nullable', 'in:daily,weekly,monthly']]);
        $scope = $this->reportScope($request);
        if (! $scope->dated()) {
            $scope = $scope->withDates(CarbonImmutable::today()->startOfMonth(), CarbonImmutable::today());
        }

        return response()->json(['data' => $this->reports->collections($scope, $request->string('period')->toString() ?: 'daily') + ['filter' => $this->filterEcho($request, $scope)]]);
    }

    public function arrears(Request $request): JsonResponse
    {
        $this->authorizeAny('reports.view');
        $scope = $this->reportScope($request);

        return response()->json(['data' => $this->reports->arrears($scope, CarbonImmutable::today()) + ['filter' => $this->filterEcho($request, $scope)]]);
    }

    public function recovery(Request $request): JsonResponse
    {
        $this->authorizeAny('reports.view');
        $scope = $this->reportScope($request);

        return response()->json(['data' => $this->reports->recovery($scope) + ['filter' => $this->filterEcho($request, $scope)]]);
    }

    public function behaviour(Request $request): JsonResponse
    {
        $this->authorizeAny('reports.view');
        $scope = $this->reportScope($request);

        return response()->json(['data' => $this->reports->behaviour($scope, CarbonImmutable::today()) + ['filter' => $this->filterEcho($request, $scope)]]);
    }

    public function segmentation(Request $request): JsonResponse
    {
        $this->authorizeAny('reports.view');
        $scope = $this->reportScope($request);

        return response()->json(['data' => ['dimensions' => $this->reports->segmentation($scope, CarbonImmutable::today()), 'filter' => $this->filterEcho($request, $scope)]]);
    }

    public function ageAnalysis(Request $request): JsonResponse
    {
        $this->authorizeAny('reports.view');
        $scope = $this->reportScope($request);

        return response()->json(['data' => ['rows' => $this->reports->ageAnalysis($scope, CarbonImmutable::today()), 'filter' => $this->filterEcho($request, $scope)]]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Reports\Financial;

use App\Services\Reports\Financial\ProfitLossReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reports → Financial → Branch Profit & Loss, Consolidated P&L and Branch Ranking.
 */
class ProfitLossController extends FinancialReportController
{
    public function __construct(private readonly ProfitLossReport $report) {}

    public function branches(Request $request): JsonResponse
    {
        $scope = $this->scope($request);

        return response()->json(['data' => $this->report->branchPnl($scope, $this->branchNames()), 'filter' => $this->filterMeta($request, $scope)]);
    }

    public function consolidated(Request $request): JsonResponse
    {
        $scope = $this->scope($request);

        return response()->json(['data' => $this->report->consolidated($scope), 'filter' => $this->filterMeta($request, $scope)]);
    }

    public function ranking(Request $request): JsonResponse
    {
        $scope = $this->scope($request);

        return response()->json(['data' => $this->report->ranking($scope, $this->branchNames()), 'filter' => $this->filterMeta($request, $scope)]);
    }
}

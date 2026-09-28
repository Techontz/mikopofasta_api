<?php

namespace App\Http\Controllers\Api\V1\Reports\Financial;

use App\Services\Reports\Financial\ControlReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reports → Financial → Expense Report (tagging), Suspense Report and Reversal Report.
 */
class ControlReportController extends FinancialReportController
{
    public function __construct(private readonly ControlReports $report) {}

    public function expenses(Request $request): JsonResponse
    {
        $scope = $this->scope($request);

        return response()->json(['data' => $this->report->expenses($scope), 'filter' => $this->filterMeta($request, $scope)]);
    }

    /**
     * Suspense position as of `to` (default today).
     */
    public function suspense(Request $request): JsonResponse
    {
        $scope = $this->scope($request);

        return response()->json(['data' => $this->report->suspense($scope), 'filter' => $this->filterMeta($request, $scope)]);
    }

    public function reversals(Request $request): JsonResponse
    {
        $scope = $this->scope($request);

        return response()->json(['data' => $this->report->reversals($scope), 'filter' => $this->filterMeta($request, $scope)]);
    }
}

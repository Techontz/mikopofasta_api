<?php

namespace App\Http\Controllers\Api\V1\Reports\Financial;

use App\Services\Reports\Financial\PeriodResultsReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reports → Financial → HQ 2% Hold and Loss Carry Forward (month-end results). Dates default to the current year.
 */
class PeriodResultController extends FinancialReportController
{
    public function __construct(private readonly PeriodResultsReport $report) {}

    public function hqHold(Request $request): JsonResponse
    {
        $scope = $this->scope($request, today()->toImmutable()->startOfYear());

        return response()->json(['data' => $this->report->hqHold($scope), 'filter' => $this->filterMeta($request, $scope)]);
    }

    public function lossCarryForward(Request $request): JsonResponse
    {
        $scope = $this->scope($request, today()->toImmutable()->startOfYear());

        return response()->json(['data' => $this->report->lossCarryForward($scope), 'filter' => $this->filterMeta($request, $scope)]);
    }
}

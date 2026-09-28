<?php

namespace App\Http\Controllers\Api\V1\Reports\Financial;

use App\Services\Reports\Financial\BalanceSheetReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reports → Financial → Balance Sheet as of a date (`to`, default today).
 */
class BalanceSheetController extends FinancialReportController
{
    public function __construct(private readonly BalanceSheetReport $report) {}

    public function __invoke(Request $request): JsonResponse
    {
        $scope = $this->scope($request);

        return response()->json(['data' => $this->report->build($scope, $scope->to), 'filter' => $this->filterMeta($request, $scope)]);
    }
}

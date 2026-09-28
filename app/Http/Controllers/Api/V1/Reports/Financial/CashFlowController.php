<?php

namespace App\Http\Controllers\Api\V1\Reports\Financial;

use App\Services\Reports\Financial\CashFlowReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reports → Financial → Master Cash Flow and Daily Position.
 */
class CashFlowController extends FinancialReportController
{
    public function __construct(private readonly CashFlowReport $report) {}

    public function index(Request $request): JsonResponse
    {
        $scope = $this->scope($request);

        return response()->json(['data' => $this->report->build($scope), 'filter' => $this->filterMeta($request, $scope)]);
    }

    /**
     * Daily Position: cash in, cash out and net position per day and per branch (defaults to today).
     */
    public function dailyPosition(Request $request): JsonResponse
    {
        $scope = $this->scope($request, today()->toImmutable());
        abort_if($scope->from->diffInDays($scope->to) > 366, 422, 'The period may not exceed one year.');

        return response()->json(['data' => $this->report->position($scope, $this->branchNames()), 'filter' => $this->filterMeta($request, $scope)]);
    }
}

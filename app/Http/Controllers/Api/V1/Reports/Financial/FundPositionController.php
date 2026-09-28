<?php

namespace App\Http\Controllers\Api\V1\Reports\Financial;

use App\Services\Reports\Financial\FundPositionReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reports → Financial → Cash & Fund Position (C5) as of a date (`as_of`, or `to`, default today): physical cash and bank
 * versus money held in fund accounts, plus reference (non-money) balances. Permission `reports.financial`.
 */
class FundPositionController extends FinancialReportController
{
    public function __construct(private readonly FundPositionReport $report) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['as_of' => ['nullable', 'date']]);
        $scope = $this->scope($request);
        $asOf = $request->filled('as_of') ? CarbonImmutable::parse($request->string('as_of')->toString())->startOfDay() : $scope->to;

        return response()->json([
            'data' => $this->report->build($scope, $asOf, $this->branchNames()),
            'filter' => ['as_of' => $asOf->toDateString()] + $this->filterMeta($request, $scope),
        ]);
    }
}

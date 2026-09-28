<?php

namespace App\Http\Controllers\Api\V1\Shares;

use App\Http\Resources\Api\V1\Shares\ShareTransactionResource;
use App\Http\Resources\Api\V1\Shares\ShareValuationResource;
use App\Services\Reports\ShareReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reports → Shares: current ownership, ownership as of a date, distribution, valuation history, transaction history,
 * issuances and transfers.
 */
class ShareReportController extends SharesController
{
    public function __construct(private readonly ShareReports $reports) {}

    public function ownership(Request $request): JsonResponse
    {
        $this->authorizeAny('shares.view');
        $request->validate(['as_of' => ['nullable', 'date', 'before_or_equal:today']]);

        $report = $this->reports->ownership($this->companyId(), $this->date($request, 'as_of'));

        return response()->json(['data' => ['rows' => $report['rows']->map(fn (array $row): array => $this->presentRow($row))->values()] + $report]);
    }

    public function distribution(): JsonResponse
    {
        $this->authorizeAny('shares.view');

        $report = $this->reports->distribution($this->companyId());

        return response()->json(['data' => ['rows' => $report['rows']->map(fn (array $row): array => $this->presentRow($row))->values()] + $report]);
    }

    public function valuations(Request $request): JsonResponse
    {
        $this->authorizeAny('shares.view');
        $this->validateRange($request);

        return response()->json(['data' => ShareValuationResource::collection($this->reports->valuations($this->companyId(), $this->date($request, 'from'), $this->date($request, 'to')))]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $this->authorizeAny('shares.view');
        $this->validateRange($request);

        return response()->json(['data' => ShareTransactionResource::collection($this->reports->transactions($this->companyId(), from: $this->date($request, 'from'), to: $this->date($request, 'to')))]);
    }

    public function issuances(Request $request): JsonResponse
    {
        $this->authorizeAny('shares.view');
        $this->validateRange($request);

        $report = $this->reports->issuances($this->companyId(), $this->date($request, 'from'), $this->date($request, 'to'));

        return response()->json(['data' => ['rows' => ShareTransactionResource::collection($report['rows']), 'totals' => $report['totals']]]);
    }

    public function transfers(Request $request): JsonResponse
    {
        $this->authorizeAny('shares.view');
        $this->validateRange($request);

        $report = $this->reports->transfers($this->companyId(), $this->date($request, 'from'), $this->date($request, 'to'));

        return response()->json(['data' => ['rows' => ShareTransactionResource::collection($report['rows']), 'totals' => $report['totals']]]);
    }

    private function validateRange(Request $request): void
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
    }
}

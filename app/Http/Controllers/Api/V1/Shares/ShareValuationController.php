<?php

namespace App\Http\Controllers\Api\V1\Shares;

use App\Http\Requests\Api\Shares\ShareReversalRequest;
use App\Http\Requests\Api\Shares\ShareValuationRequest;
use App\Http\Resources\Api\V1\Shares\ShareValuationResource;
use App\Models\ShareValuation;
use App\Services\Reports\ShareReports;
use App\Services\Shares\ShareValuations;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shares → Share Valuations: value history (never overwritten) and new share values. Memorandum records only.
 */
class ShareValuationController extends SharesController
{
    public function __construct(
        private readonly ShareValuations $valuations,
        private readonly ShareReports $reports,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('shares.view');
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return response()->json(['data' => ShareValuationResource::collection($this->reports->valuations($this->companyId(), $this->date($request, 'from'), $this->date($request, 'to')))]);
    }

    public function store(ShareValuationRequest $request): JsonResponse
    {
        $result = $this->valuations->revalue(
            $this->companyId(),
            $request->float('new_value'),
            CarbonImmutable::parse($request->string('valuation_date')->toString()),
            $request->string('reason')->toString(),
            $this->currentEmployee(),
            $request->input('idempotency_key'),
        );

        return $this->message(
            $result['created'] ? 'Share Value Updated successfully' : 'Share value was already recorded',
            $result['created'] ? 201 : 200,
            ['data' => new ShareValuationResource($result['valuation']->load('performer'))],
        );
    }

    public function reverse(ShareReversalRequest $request, ShareValuation $shareValuation): JsonResponse
    {
        $this->ensureCompany($shareValuation);

        $valuation = $this->valuations->reverse($shareValuation, $request->string('reason')->toString(), $this->currentEmployee());

        return $this->message('Share Valuation Reversed successfully', 200, ['data' => new ShareValuationResource($valuation->load(['performer', 'reverser']))]);
    }
}

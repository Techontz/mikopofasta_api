<?php

namespace App\Http\Controllers\Api\V1\Shares;

use App\Http\Requests\Api\Shares\ShareStructureRequest;
use App\Http\Requests\Api\Shares\UpdateShareStructureRequest;
use App\Http\Resources\Api\V1\Shares\ShareStructureResource;
use App\Http\Resources\Api\V1\Shares\ShareTransactionResource;
use App\Http\Resources\Api\V1\Shares\ShareValuationResource;
use App\Services\Reports\ShareReports;
use App\Services\Shares\ShareIssuance;
use App\Services\Shares\ShareRegister;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shares → Overview and the share structure set-up (capital basis, initial shares, authorised limit, initial allocation).
 */
class ShareOverviewController extends SharesController
{
    public function __construct(
        private readonly ShareRegister $register,
        private readonly ShareReports $reports,
        private readonly ShareIssuance $issuance,
    ) {}

    /**
     * Totals, current share value and company share valuation, recent movements and value changes, and the ownership
     * distribution (Shareholder | Shares | Ownership % | Current Share Value | Holding Value).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('shares.view');

        $companyId = $this->companyId();
        $structure = $this->register->structure($companyId);
        $ownership = $this->reports->ownership($companyId);

        return response()->json(['data' => [
            'has_structure' => $structure !== null,
            'structure' => $structure === null ? null : new ShareStructureResource($structure->load('creator')),
            'total_issued_shares' => $ownership['total_shares'],
            'authorised_shares' => $structure?->authorised_shares,
            'available_shares' => $structure === null ? null : $this->register->availableShares($structure),
            'current_share_value' => $ownership['share_value'],
            'total_valuation' => $ownership['total_valuation'],
            'shareholders_with_shares' => $ownership['shareholders_with_shares'],
            'registered_shareholders' => $ownership['rows']->count(),
            'register_consistent' => $this->register->verify($companyId)['consistent'],
            'distribution' => $ownership['rows']->where('shares', '>', 0)->sortByDesc('shares')->values()->map(fn (array $row): array => $this->presentRow($row)),
            'recent_transactions' => ShareTransactionResource::collection($this->reports->transactions($companyId, limit: 8)),
            'recent_valuations' => ShareValuationResource::collection($this->reports->valuations($companyId)->take(5)),
        ]]);
    }

    public function structure(): JsonResponse
    {
        $this->authorizeAny('shares.view');

        $structure = $this->register->structure($this->companyId());

        return response()->json(['data' => $structure === null ? null : new ShareStructureResource($structure->load('creator'))]);
    }

    public function store(ShareStructureRequest $request): JsonResponse
    {
        $result = $this->issuance->establish(
            $this->companyId(),
            $request->float('capital_basis'),
            $request->integer('total_shares'),
            $request->filled('authorised_shares') ? $request->integer('authorised_shares') : null,
            CarbonImmutable::parse($request->string('established_on')->toString()),
            $request->input('notes'),
            array_map(fn (array $allocation): array => [
                'share_holder_id' => (int) $allocation['share_holder_id'],
                'shares' => (int) $allocation['shares'],
                'treatment' => (string) $allocation['treatment'],
                'capital_id' => isset($allocation['capital_id']) ? (int) $allocation['capital_id'] : null,
                'pay_method' => $allocation['pay_method'] ?? null,
                'bank_account_id' => isset($allocation['bank_account_id']) ? (int) $allocation['bank_account_id'] : null,
                'receipt_number' => $allocation['receipt_number'] ?? null,
                'cheque_number' => $allocation['cheque_number'] ?? null,
            ], array_values($request->input('allocations', []))),
            $this->currentEmployee(),
            $request->input('idempotency_key'),
        );

        return $this->message(
            $result['created'] ? 'Share Structure Created successfully' : 'Share structure was already created',
            $result['created'] ? 201 : 200,
            ['data' => new ShareStructureResource($result['structure'])],
        );
    }

    /**
     * Change the authorised share limit; it can never be set below the shares already issued.
     */
    public function update(UpdateShareStructureRequest $request): JsonResponse
    {
        $limit = $request->filled('authorised_shares') ? $request->integer('authorised_shares') : null;

        $structure = DB::transaction(function () use ($limit) {
            $structure = $this->register->lockStructure($this->companyId());
            $issued = $this->register->issuedShares($this->companyId());
            if ($limit !== null && $limit < $issued) {
                throw ValidationException::withMessages(['authorised_shares' => 'The authorised share limit cannot be lower than the '.number_format($issued).' shares already issued']);
            }

            $structure->update(['authorised_shares' => $limit]);

            return $structure;
        });

        return $this->message('Share Structure Updated successfully', 200, ['data' => new ShareStructureResource($structure)]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Treasury;

use App\Domain\Treasury\Actions\CreateShareholderAction;
use App\Domain\Treasury\Actions\DeleteShareholderAction;
use App\Domain\Treasury\Actions\UpdateShareholderAction;
use App\Domain\Treasury\DTOs\ShareholderData;
use App\Domain\Treasury\Services\ShareholderOwnership;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Treasury\Concerns\AuthorizesCapital;
use App\Http\Requests\Treasury\StoreShareholderRequest;
use App\Http\Resources\CapitalContributionResource;
use App\Http\Resources\ShareholderResource;
use App\Models\CapitalContribution;
use App\Models\Shareholder;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Capital → Share Holders.
 *
 * Unpaginated: a company has a handful of shareholders and the Add Capital
 * form needs the whole list to populate its selector.
 */
final class ShareholderController extends Controller
{
    use AuthorizesCapital;

    public function __construct(private readonly ShareholderOwnership $ownership) {}

    /**
     * GET /api/v1/shareholders
     *
     * Each shareholder carries `totalContributed` and `ownershipPercentage`;
     * `meta.totalContributed` is the denominator both were computed against.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeCapital('view', $request);

        $shareholders = Shareholder::query()
            ->withCount('contributions')
            ->orderBy('full_name')
            ->get();

        $shares = $this->ownership->all();

        foreach ($shareholders as $shareholder) {
            $this->attachOwnership($shareholder, $shares);
        }

        return ApiResponse::data(
            ShareholderResource::collection($shareholders),
            meta: ['totalContributed' => $this->ownership->totalContributed()->toDecimalString()],
        );
    }

    /**
     * GET /api/v1/shareholders/{shareholder}
     *
     * One shareholder's capital history — every contribution with its
     * reference, the company account it landed in and the entry that posted
     * it. Removed (reversed) contributions are listed too, flagged by
     * `removedAt`, so the history is never shortened by a correction.
     */
    public function show(Request $request, Shareholder $shareholder): JsonResponse
    {
        $this->authorizeCapital('view', $request);

        $shareholder->loadCount('contributions');
        $this->attachOwnership($shareholder, $this->ownership->all());

        $contributions = CapitalContribution::withTrashed()
            ->with(['receivedAccount', 'journalEntry', 'recorder'])
            ->where('shareholder_id', $shareholder->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return ApiResponse::data(
            new ShareholderResource($shareholder),
            meta: [
                'totalContributed' => $this->ownership->totalContributed()->toDecimalString(),
                'contributions' => CapitalContributionResource::collection($contributions)->resolve($request),
            ],
        );
    }

    /** POST /api/v1/shareholders */
    public function store(StoreShareholderRequest $request, CreateShareholderAction $action): JsonResponse
    {
        $this->authorizeCapital('manage', $request);

        $shareholder = $action->handle(ShareholderData::fromArray($request->validated()), $this->actor($request));

        return ApiResponse::data(new ShareholderResource($shareholder), status: Response::HTTP_CREATED);
    }

    /** PUT /api/v1/shareholders/{shareholder} */
    public function update(
        StoreShareholderRequest $request,
        Shareholder $shareholder,
        UpdateShareholderAction $action,
    ): JsonResponse {
        $this->authorizeCapital('manage', $request);

        $updated = $action->handle($shareholder, ShareholderData::fromArray($request->validated()), $this->actor($request));

        return ApiResponse::data(new ShareholderResource($updated));
    }

    /** DELETE /api/v1/shareholders/{shareholder} */
    public function destroy(Request $request, Shareholder $shareholder, DeleteShareholderAction $action): JsonResponse
    {
        $this->authorizeCapital('manage', $request);

        $action->handle($shareholder, $this->actor($request));

        return ApiResponse::data(['message' => 'Shareholder deleted.']);
    }

    /** @param array<int, array{contributed: Money, percentage: string}> $shares */
    private function attachOwnership(Shareholder $shareholder, array $shares): void
    {
        $share = $shares[$shareholder->id] ?? null;

        $shareholder->setAttribute(
            'ownership_contributed',
            ($share['contributed'] ?? Money::zero())->toDecimalString(),
        );
        $shareholder->setAttribute(
            'ownership_percentage',
            $share['percentage'] ?? $this->ownership->percentage(Money::zero(), Money::zero()),
        );
    }
}

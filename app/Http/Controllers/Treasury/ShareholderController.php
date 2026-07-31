<?php

declare(strict_types=1);

namespace App\Http\Controllers\Treasury;

use App\Domain\Treasury\Actions\CreateShareholderAction;
use App\Domain\Treasury\Actions\DeleteShareholderAction;
use App\Domain\Treasury\Actions\UpdateShareholderAction;
use App\Domain\Treasury\DTOs\ShareholderData;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Treasury\Concerns\AuthorizesCapital;
use App\Http\Requests\Treasury\StoreShareholderRequest;
use App\Http\Resources\ShareholderResource;
use App\Models\Shareholder;
use App\Support\ApiResponse;
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

    /** GET /api/v1/shareholders */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeCapital('view', $request);

        $shareholders = Shareholder::query()
            ->withCount('contributions')
            ->orderBy('full_name')
            ->get();

        return ApiResponse::data(ShareholderResource::collection($shareholders));
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
}

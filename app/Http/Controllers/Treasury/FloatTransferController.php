<?php

declare(strict_types=1);

namespace App\Http\Controllers\Treasury;

use App\Domain\Treasury\Actions\DecideFloatTransferAction;
use App\Domain\Treasury\Actions\DeleteFloatTransferAction;
use App\Domain\Treasury\Actions\RequestFloatTransferAction;
use App\Domain\Treasury\DTOs\FloatTransferData;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Treasury\Concerns\AuthorizesCapital;
use App\Http\Requests\Treasury\RejectFloatTransferRequest;
use App\Http\Requests\Treasury\StoreFloatTransferRequest;
use App\Http\Resources\FloatTransferResource;
use App\Models\FloatTransfer;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The three float screens — Capital → Float, Float Branch To Branch, and
 * Aproved Float — plus Float Ac-Ac. All four read this one collection, filtered.
 *
 * Unpaginated: each screen is a working queue, and the legacy tables page in
 * the browser.
 */
final class FloatTransferController extends Controller
{
    use AuthorizesCapital;

    /**
     * GET /api/v1/float-transfers?kind=&status=&from=&to=
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeCapital('view', $request);

        $transfers = FloatTransfer::query()
            ->with(['fromBranch', 'toBranch', 'fromAccount', 'toAccount', 'requester', 'approver'])
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->latest('id')
            ->get();

        $total = Money::sum($transfers->map(fn (FloatTransfer $t): Money => Money::of($t->amount)));

        return ApiResponse::data(
            FloatTransferResource::collection($transfers),
            meta: ['total' => $total->toDecimalString()],
        );
    }

    /** POST /api/v1/float-transfers */
    public function store(StoreFloatTransferRequest $request, RequestFloatTransferAction $action): JsonResponse
    {
        $this->authorizeCapital('manage', $request);

        $transfer = $action->handle(FloatTransferData::fromArray($request->validated()), $this->actor($request));

        return ApiResponse::data(new FloatTransferResource($transfer), status: Response::HTTP_CREATED);
    }

    /** POST /api/v1/float-transfers/{transfer}/approve */
    public function approve(Request $request, FloatTransfer $transfer, DecideFloatTransferAction $action): JsonResponse
    {
        // §14: the requester may not approve their own transfer.
        $this->authorizeDecision($request, $transfer);

        return ApiResponse::data(new FloatTransferResource($action->approve($transfer, $this->actor($request))));
    }

    /** POST /api/v1/float-transfers/{transfer}/reject */
    public function reject(
        RejectFloatTransferRequest $request,
        FloatTransfer $transfer,
        DecideFloatTransferAction $action,
    ): JsonResponse {
        $this->authorizeDecision($request, $transfer);

        $updated = $action->reject($transfer, (string) $request->validated('reason'), $this->actor($request));

        return ApiResponse::data(new FloatTransferResource($updated));
    }

    /** DELETE /api/v1/float-transfers/{transfer} */
    public function destroy(Request $request, FloatTransfer $transfer, DeleteFloatTransferAction $action): JsonResponse
    {
        $this->authorizeCapital('manage', $request);

        $action->handle($transfer, $this->actor($request));

        return ApiResponse::data(['message' => 'Float transfer deleted.']);
    }
}

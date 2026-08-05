<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Domain\Accounting\Actions\DecideReserveUtilisationAction;
use App\Domain\Accounting\Actions\RequestReserveUtilisationAction;
use App\Domain\Accounting\DTOs\ReserveUtilisationData;
use App\Domain\Accounting\Policies\AccountingPolicy;
use App\Domain\Accounting\Services\ReserveBalanceReader;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreReserveUtilisationRequest;
use App\Http\Requests\Treasury\RejectFloatTransferRequest;
use App\Http\Resources\ReserveUtilisationResource;
use App\Models\ReserveUtilisation;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Reserve fund — Decision Register D1.
 *
 * "Reserve transfers require Admin approval. Branches cannot directly use
 * Reserve funds. Reserve belongs to Headquarters / Administration."
 *
 * Finance raises a request; Admin approves or rejects it; money moves only on
 * approval. The same three-step shape as float transfers and expenses, so the
 * approval screens behave the way every other approval screen does.
 */
final class ReserveUtilisationController extends Controller
{
    /**
     * GET /api/v1/reserve/utilisations?status=
     *
     * Unpaginated: this is a working queue, not an archive, and the legacy
     * tables page in the browser.
     */
    public function index(Request $request, ReserveBalanceReader $reserve): JsonResponse
    {
        $this->authorizeAccounting('viewReserve', $request);

        $requests = ReserveUtilisation::query()
            ->with(['requester', 'approver', 'targetBranch'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('id')
            ->get();

        return ApiResponse::data(
            ReserveUtilisationResource::collection($requests),

            // The balance is what makes the queue actionable: an approver
            // needs to know what the fund holds before releasing from it.
            meta: ['reserveBalance' => $reserve->balance()->toDecimalString()],
        );
    }

    /** POST /api/v1/reserve/utilisations */
    public function store(
        StoreReserveUtilisationRequest $request,
        RequestReserveUtilisationAction $action,
    ): JsonResponse {
        $this->authorizeAccounting('requestReserve', $request);

        $utilisation = $action->handle(
            ReserveUtilisationData::fromArray($request->validated()),
            $this->actor($request),
        );

        return ApiResponse::data(new ReserveUtilisationResource($utilisation), status: Response::HTTP_CREATED);
    }

    /** POST /api/v1/reserve/utilisations/{utilisation}/approve */
    public function approve(
        Request $request,
        ReserveUtilisation $utilisation,
        DecideReserveUtilisationAction $action,
    ): JsonResponse {
        $this->authorizeDecision($request, $utilisation);

        return ApiResponse::data(
            new ReserveUtilisationResource($action->approve($utilisation, $this->actor($request))),
        );
    }

    /**
     * POST /api/v1/reserve/utilisations/{utilisation}/reject
     *
     * Reuses the float transfer's reject request: both ask for one thing, a
     * reason of the same shape, and a second identical class would only be a
     * second place to change the minimum length.
     */
    public function reject(
        RejectFloatTransferRequest $request,
        ReserveUtilisation $utilisation,
        DecideReserveUtilisationAction $action,
    ): JsonResponse {
        $this->authorizeDecision($request, $utilisation);

        $decided = $action->reject(
            $utilisation,
            (string) $request->validated('reason'),
            $this->actor($request),
        );

        return ApiResponse::data(new ReserveUtilisationResource($decided));
    }

    private function authorizeAccounting(string $ability, Request $request): void
    {
        abort_unless(app(AccountingPolicy::class)->{$ability}($this->actor($request)), Response::HTTP_FORBIDDEN);
    }

    /** §14: whoever raised the request may not be the one who decides it. */
    private function authorizeDecision(Request $request, ReserveUtilisation $utilisation): void
    {
        abort_unless(
            app(AccountingPolicy::class)->decideReserve($this->actor($request), $utilisation),
            Response::HTTP_FORBIDDEN,
        );
    }
}

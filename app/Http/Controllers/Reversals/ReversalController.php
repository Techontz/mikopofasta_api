<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reversals;

use App\Domain\Ledger\Enums\ReversalStatus;
use App\Domain\Reversals\Actions\DecideReversalAction;
use App\Domain\Reversals\Actions\RequestReversalAction;
use App\Domain\Reversals\Enums\ReversalType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reversals\DecideReversalRequest;
use App\Http\Requests\Reversals\RequestReversalRequest;
use App\Http\Resources\ReversalRequestResource;
use App\Models\CustomerAdvancePayment;
use App\Models\DisbursementBatch;
use App\Models\JournalEntry;
use App\Models\LoanSchedule;
use App\Models\Payment;
use App\Models\ReversalRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The reversal desk — §5, §14.
 *
 * One queue for every reversible thing, because they are one control: a
 * request raised by somebody who cannot approve it, decided by somebody who
 * did not raise it. Splitting it per type would have given an approver four
 * screens to watch and three chances to miss one.
 *
 * Nothing here reverses anything directly. `store` records a request; approval
 * runs the executor. The two are different endpoints because they are
 * different grants held by different people.
 */
final class ReversalController extends Controller
{
    /**
     * GET /api/v1/reversals
     *
     * Filterable by status and type — the approvals screen asks for pending,
     * the history screen asks for everything.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ReversalRequest::class);

        $query = ReversalRequest::query()
            ->with([
                'journalEntry', 'reversalEntry', 'payment',
                'disbursementBatch', 'loanSchedule', 'advancePayment', 'loan', 'requester', 'approver',
            ])
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->string('status')->toString()),
            )
            ->when(
                $request->filled('reversal_type'),
                fn ($q) => $q->where('reversal_type', $request->string('reversal_type')->toString()),
            )
            ->when(
                $request->filled('loan_id'),
                fn ($q) => $q->where('loan_id', $request->integer('loan_id')),
            )
            ->latest('id');

        return ApiResponse::paginated(
            $query->paginate(ApiResponse::perPage($request->query('per_page')))->withQueryString(),
            ReversalRequestResource::class,
        );
    }

    /**
     * GET /api/v1/reversals/pending — the approver's queue.
     *
     * Its own endpoint rather than a filter on `index`, because it is what the
     * badge counts and what the approvals screen polls; a caller must not be
     * able to widen it by dropping a query parameter.
     */
    public function pending(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ReversalRequest::class);

        $requests = ReversalRequest::query()
            ->with([
                'journalEntry', 'payment', 'disbursementBatch',
                'loanSchedule', 'advancePayment', 'loan', 'requester',
            ])
            ->where('status', ReversalStatus::Pending)
            ->latest('id')
            ->get();

        return ApiResponse::data(
            ReversalRequestResource::collection($requests),
            ['count' => $requests->count()],
        );
    }

    /**
     * GET /api/v1/reversals/{reversalRequest}
     */
    public function show(Request $request, ReversalRequest $reversalRequest): JsonResponse
    {
        $this->authorize('view', $reversalRequest);

        return ApiResponse::data(new ReversalRequestResource($reversalRequest->load([
            'journalEntry', 'reversalEntry', 'payment',
            'disbursementBatch', 'loanSchedule', 'advancePayment', 'loan', 'requester', 'approver',
        ])));
    }

    /**
     * POST /api/v1/reversals — raise one. Requires `ledger.reverse.request`.
     */
    public function store(RequestReversalRequest $request, RequestReversalAction $action): JsonResponse
    {
        $this->authorize('request', ReversalRequest::class);

        $type = ReversalType::from((string) $request->validated('reversal_type'));

        $created = $action->handle(
            $type,
            $this->subject($type, (int) $request->validated('subject_id')),
            (string) $request->validated('reason'),
            $this->actor($request),
        );

        return ApiResponse::data(
            new ReversalRequestResource($created->load([
                'journalEntry', 'payment', 'disbursementBatch', 'loanSchedule', 'advancePayment', 'loan', 'requester',
            ])),
            status: Response::HTTP_CREATED,
        );
    }

    /**
     * POST /api/v1/reversals/{reversalRequest}/approve
     *
     * Requires `ledger.reverse.approve` — a different grant from raising one —
     * and the action refuses an approver who is the requester.
     */
    public function approve(
        Request $request,
        ReversalRequest $reversalRequest,
        DecideReversalAction $action,
    ): JsonResponse {
        $this->authorize('approve', $reversalRequest);

        $decided = $action->approve($reversalRequest, $this->actor($request));

        return ApiResponse::data(new ReversalRequestResource($decided));
    }

    /**
     * POST /api/v1/reversals/{reversalRequest}/reject
     */
    public function reject(
        DecideReversalRequest $request,
        ReversalRequest $reversalRequest,
        DecideReversalAction $action,
    ): JsonResponse {
        $this->authorize('reject', $reversalRequest);

        $note = $request->validated('note');

        $decided = $action->reject(
            $reversalRequest,
            $note === null ? null : (string) $note,
            $this->actor($request),
        );

        return ApiResponse::data(new ReversalRequestResource($decided));
    }

    /**
     * The subject id means a different table per type — the form request has
     * already checked it exists in the right one.
     */
    private function subject(ReversalType $type, int $id): Payment|DisbursementBatch|LoanSchedule|CustomerAdvancePayment|JournalEntry
    {
        return match ($type) {
            ReversalType::Payment => Payment::query()->findOrFail($id),
            ReversalType::Disbursement => DisbursementBatch::query()->findOrFail($id),
            ReversalType::Penalty => LoanSchedule::query()->findOrFail($id),
            ReversalType::AdvancePayment => CustomerAdvancePayment::query()->findOrFail($id),
            ReversalType::Ledger => JournalEntry::query()->findOrFail($id),
        };
    }
}

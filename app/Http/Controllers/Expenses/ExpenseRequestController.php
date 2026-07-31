<?php

declare(strict_types=1);

namespace App\Http\Controllers\Expenses;

use App\Domain\Expenses\Actions\CommentOnExpenseRequestAction;
use App\Domain\Expenses\Actions\DecideExpenseRequestAction;
use App\Domain\Expenses\Actions\DeleteExpenseRequestAction;
use App\Domain\Expenses\Actions\RequestExpenseAction;
use App\Domain\Expenses\DTOs\ExpenseRequestData;
use App\Domain\Expenses\Enums\ExpenseRequestStatus;
use App\Domain\Expenses\Exceptions\ExpenseException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Expenses\Concerns\AuthorizesExpenses;
use App\Http\Requests\Expenses\CommentOnExpenseRequestRequest;
use App\Http\Requests\Expenses\DecideExpenseRequestRequest;
use App\Http\Requests\Expenses\StoreExpenseRequestRequest;
use App\Http\Resources\ExpenseRequestResource;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRequest;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The four expense claim screens — branch and headquarters, requested and
 * approved.
 *
 * One collection filtered four ways, which is the same decision the frontend
 * made when it modelled all four with a single `ExpenseClaimSchema`: an
 * approved request is not a different record from a pending one, it is the same
 * record later. Four endpoints would be four chances for the totals on one
 * screen to stop agreeing with the rows on another.
 *
 * Unpaginated, matching the other operational queues, and the response carries
 * the total the legacy screen prints in its footer.
 */
final class ExpenseRequestController extends Controller
{
    use AuthorizesExpenses;

    /** GET /api/v1/expense-requests?scope=&status=&branch_id=&from=&to= */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeExpenses('view', $request);

        $requests = ExpenseRequest::query()
            ->withListRelations()
            ->when($request->filled('scope'), fn ($q) => $q->where('scope', $request->string('scope')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when(
                $request->filled('expense_category_id'),
                fn ($q) => $q->where('expense_category_id', $request->integer('expense_category_id')),
            )
            ->when($request->filled('from'), fn ($q) => $q->whereDate('requested_on', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('requested_on', '<=', $request->date('to')))
            ->orderByDesc('requested_on')
            ->orderByDesc('id')
            ->get();

        /*
         * Two totals, because they answer different questions. `total` is what
         * the visible rows add up to — the legacy footer. `approvedTotal` is
         * what has actually been spent, and it is the one that ties to the
         * ledger; on an unfiltered list the difference between them is the
         * value of the queue still waiting for a decision.
         */
        $total = Money::sum($requests->map(fn (ExpenseRequest $r): Money => Money::of($r->amount)));
        $approved = Money::sum(
            $requests->where('status', ExpenseRequestStatus::Approved)
                ->map(fn (ExpenseRequest $r): Money => Money::of($r->amount)),
        );

        return ApiResponse::data(
            ExpenseRequestResource::collection($requests),
            meta: [
                'total' => $total->toDecimalString(),
                'approvedTotal' => $approved->toDecimalString(),
                'count' => $requests->count(),
            ],
        );
    }

    /** GET /api/v1/expense-requests/{expenseRequest} */
    public function show(Request $request, ExpenseRequest $expenseRequest): JsonResponse
    {
        $this->authorizeExpenses('view', $request);

        return ApiResponse::data(
            new ExpenseRequestResource($expenseRequest->load(ExpenseRequest::LIST_RELATIONS)),
        );
    }

    /** POST /api/v1/expense-requests */
    public function store(StoreExpenseRequestRequest $request, RequestExpenseAction $action): JsonResponse
    {
        $this->authorizeExpenses('request', $request);

        $data = ExpenseRequestData::fromArray($request->validated());
        $category = ExpenseCategory::query()->findOrFail($data->categoryId);

        $expenseRequest = $action->handle($category, $data, $this->actor($request));

        return ApiResponse::data(new ExpenseRequestResource($expenseRequest), status: Response::HTTP_CREATED);
    }

    /**
     * POST /api/v1/expense-requests/{expenseRequest}/decide
     *
     * One endpoint for both buttons. Approve and reject are the same
     * transition with a different outcome, and splitting them would mean two
     * places to keep the §14 self-approval check.
     */
    public function decide(
        DecideExpenseRequestRequest $request,
        ExpenseRequest $expenseRequest,
        DecideExpenseRequestAction $action,
    ): JsonResponse {
        $this->authorizeExpenseDecision($request, $expenseRequest);

        $decision = ExpenseRequestStatus::from((string) $request->validated('decision'));

        $decided = $action->handle(
            $expenseRequest,
            $decision,
            $request->validated('comment'),
            $this->actor($request),
        );

        return ApiResponse::data(new ExpenseRequestResource($decided));
    }

    /** PATCH /api/v1/expense-requests/{expenseRequest}/comment */
    public function comment(
        CommentOnExpenseRequestRequest $request,
        ExpenseRequest $expenseRequest,
        CommentOnExpenseRequestAction $action,
    ): JsonResponse {
        $this->authorizeExpenses('comment', $request);

        $updated = $action->handle($expenseRequest, $request->validated('comment'), $this->actor($request));

        return ApiResponse::data(new ExpenseRequestResource($updated));
    }

    /** DELETE /api/v1/expense-requests/{expenseRequest} */
    public function destroy(
        Request $request,
        ExpenseRequest $expenseRequest,
        DeleteExpenseRequestAction $action,
    ): JsonResponse {
        /*
         * An approved request is refused by the policy, which returns false for
         * anything already decided — indistinguishable, from the caller's side,
         * from not being allowed to delete at all. The distinction matters:
         * "you may not" and "this one cannot be" are different answers, so the
         * decided case is turned into the module's own message here.
         */
        if (! $expenseRequest->status->isDecidable()) {
            throw $expenseRequest->journal_entry_id !== null
                ? ExpenseException::alreadyPosted()
                : ExpenseException::notPending();
        }

        $this->authorizeExpenseDeletion($request, $expenseRequest);

        $action->handle($expenseRequest, $this->actor($request));

        return ApiResponse::data(['message' => 'Expense request withdrawn.']);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Treasury;

use App\Domain\Treasury\Actions\DecideHqTransactionAction;
use App\Domain\Treasury\Actions\RequestHqTransactionAction;
use App\Domain\Treasury\DTOs\HqTransactionData;
use App\Domain\Treasury\Enums\HqTransactionDirection;
use App\Domain\Treasury\Enums\HqTransactionStatus;
use App\Domain\Treasury\Policies\CapitalPolicy;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Treasury\Concerns\AuthorizesCapital;
use App\Http\Requests\Treasury\DecideHqTransactionRequest;
use App\Http\Requests\Treasury\StoreHqTransactionRequest;
use App\Http\Resources\HqAccountResource;
use App\Http\Resources\HqTransactionResource;
use App\Models\HqAccount;
use App\Models\HqAccountTransfer;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The three Headquarters Transaction screens — Account Balance, Requested
 * Transactions, Approved Transactions.
 *
 * The last two are one collection filtered, the same decision the Expenses
 * queues make: an approved transaction is not a different record from a pending
 * one, it is the same record later.
 *
 * See docs/modules/headquarters.md.
 */
final class HqTransactionController extends Controller
{
    use AuthorizesCapital;

    /**
     * GET /api/v1/hq-accounts
     *
     * The seven pots and what each holds — the balance screen. The frontend has
     * been drawing these from a hardcoded constant, which was right while no
     * endpoint existed and is wrong now that a balance can move.
     */
    public function accounts(Request $request): JsonResponse
    {
        $this->authorizeCapital('view', $request);

        $accounts = HqAccount::query()->orderBy('id')->get();

        return ApiResponse::data(
            HqAccountResource::collection($accounts),
            meta: [
                // The legacy screen prints a total under the seven; it is a sum
                // of what is there rather than a stored figure, so the two can
                // never disagree.
                'total' => Money::sum($accounts->map(fn (HqAccount $a): Money => $a->balance()))->toDecimalString(),
            ],
        );
    }

    /** GET /api/v1/hq-transactions?status=&direction=&branch_id=&from=&to= */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeCapital('view', $request);

        $transactions = HqAccountTransfer::query()
            ->withListRelations()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->string('direction')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('requested_on', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('requested_on', '<=', $request->date('to')))
            ->orderByDesc('requested_on')
            ->orderByDesc('id')
            ->get();

        /*
         * The position, computed the same way the frontend's `hqBalance()`
         * does — approved movements only, `internal` counted in neither total
         * because moving money between two pots does not change how much there
         * is. Returned rather than left to the client so a filtered list and
         * its own summary cannot drift apart.
         */
        $approved = $transactions->where('status', HqTransactionStatus::Approved);

        $sum = fn (HqTransactionDirection $d): string => Money::sum(
            $approved->where('direction', $d)->map(fn (HqAccountTransfer $t): Money => $t->amount()),
        )->toDecimalString();

        $income = $sum(HqTransactionDirection::In);
        $expense = $sum(HqTransactionDirection::Out);

        return ApiResponse::data(
            HqTransactionResource::collection($transactions),
            meta: [
                'income' => $income,
                'expense' => $expense,
                'net' => Money::of($income)->subtract(Money::of($expense))->toDecimalString(),
                'approvedCount' => $approved->count(),
                'count' => $transactions->count(),
            ],
        );
    }

    /** POST /api/v1/hq-transactions */
    public function store(StoreHqTransactionRequest $request, RequestHqTransactionAction $action): JsonResponse
    {
        $this->authorizeCapital('manage', $request);

        $transfer = $action->handle(
            HqTransactionData::fromArray($request->validated()),
            $this->actor($request),
        );

        return ApiResponse::data(new HqTransactionResource($transfer), status: Response::HTTP_CREATED);
    }

    /** POST /api/v1/hq-transactions/{transaction}/decide */
    public function decide(
        DecideHqTransactionRequest $request,
        HqAccountTransfer $transaction,
        DecideHqTransactionAction $action,
    ): JsonResponse {
        // §14: whoever raised it may not be the one who approves it.
        abort_unless(
            app(CapitalPolicy::class)->decideHqTransaction($this->actor($request), $transaction),
            Response::HTTP_FORBIDDEN,
        );

        $decided = $action->handle(
            $transaction,
            HqTransactionStatus::from((string) $request->validated('decision')),
            $this->actor($request),
        );

        return ApiResponse::data(new HqTransactionResource($decided));
    }
}

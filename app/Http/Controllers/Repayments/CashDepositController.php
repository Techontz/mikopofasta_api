<?php

declare(strict_types=1);

namespace App\Http\Controllers\Repayments;

use App\Domain\Organization\Services\BranchScope;
use App\Domain\Organization\Services\BranchScopeGuard;
use App\Domain\Repayments\Actions\ReconcileCashDepositAction;
use App\Domain\Repayments\Actions\RecordCashDepositAction;
use App\Domain\Repayments\Enums\CashDepositStatus;
use App\Domain\Repayments\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Repayments\StoreCashDepositRequest;
use App\Http\Resources\CashDepositResource;
use App\Http\Resources\PaymentResource;
use App\Models\CashDeposit;
use App\Models\Payment;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bank reconciliation — §15.3's `POST /finance/bank-reconciliation`, and the
 * gap that stopped any cash payment ever reaching `confirmed`.
 *
 * Two roles, two steps, matching the client's description of the branch flow:
 * the teller banks the cash and names the payments it covers; Finance verifies
 * that declaration and confirms it. Neither can do the other's step —
 * `repayments.cash_entry` records a deposit, `repayments.reconcile` confirms
 * one, and no role holds both by default.
 */
final class CashDepositController extends Controller
{
    public function __construct(
        private readonly BranchScope $scope,
        private readonly BranchScopeGuard $guard,
    ) {}

    /**
     * GET /api/v1/cash-deposits?status=&branch_id=
     *
     * Finance's reconciliation queue, and a teller's record of what they have
     * banked. Branch-scoped: §13 pins a branch user to their own.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $query = CashDeposit::query()
            ->with(['branch', 'bankAccount', 'teller'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')));

        // §13: a branch user sees their own branch's deposits and no others.
        $deposits = $this->scope->applyToColumn($query, $this->actor($request))
            ->latest('id')
            ->get();

        $pending = $deposits->where('status', CashDepositStatus::Pending);

        return ApiResponse::data(
            CashDepositResource::collection($deposits),
            meta: [
                'total' => Money::sum($deposits->map(fn (CashDeposit $d): Money => $d->amountMoney()))->toDecimalString(),
                'pendingTotal' => Money::sum($pending->map(fn (CashDeposit $d): Money => $d->amountMoney()))->toDecimalString(),
                'pendingCount' => $pending->count(),
            ],
        );
    }

    /**
     * GET /api/v1/cash-deposits/unbanked?branch_id=
     *
     * The cash payments a teller has taken but not yet banked — what the
     * deposit form offers. Without it the teller would be typing payment ids
     * from memory, which is how mismatches start.
     */
    public function unbanked(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $banked = CashDeposit::query()
            ->whereNotNull('matched_payment_ids')
            ->pluck('matched_payment_ids')
            ->flatten()
            ->map(fn ($id): int => (int) $id)
            ->all();

        $query = Payment::query()
            ->with(['loan', 'customer'])
            ->where('status', PaymentStatus::PendingVerification)
            ->whereNotIn('id', $banked)
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')));

        $payments = $this->scope->applyToColumn($query, $this->actor($request))
            ->latest('id')
            ->get();

        return ApiResponse::data(
            PaymentResource::collection($payments),
            meta: [
                'total' => Money::sum(
                    $payments->map(fn (Payment $p): Money => Money::of($p->amount)),
                )->toDecimalString(),
            ],
        );
    }

    /** POST /api/v1/cash-deposits */
    public function store(StoreCashDepositRequest $request, RecordCashDepositAction $action): JsonResponse
    {
        $this->authorize('recordCash', Payment::class);

        $teller = $this->actor($request);
        $branchId = (int) $request->validated('branch_id');

        $this->guard->authorizeBranchId($teller, $branchId, Payment::class);

        /** @var list<int> $paymentIds */
        $paymentIds = array_map('intval', (array) $request->validated('payment_ids'));

        $deposit = $action->handle(
            branchId: $branchId,
            bankAccountId: (int) $request->validated('bank_account_id'),
            amount: Money::of((string) $request->validated('amount')),
            paymentIds: $paymentIds,
            slip: $request->file('slip'),
            teller: $teller,
        );

        return ApiResponse::data(new CashDepositResource($deposit), status: Response::HTTP_CREATED);
    }

    /**
     * POST /api/v1/cash-deposits/{deposit}/reconcile
     *
     * Finance's confirmation. Posts `Dr Bank · Cr Teller Cash` and moves every
     * named payment to `confirmed` — the transition nothing in the system could
     * previously make.
     */
    public function reconcile(
        Request $request,
        CashDeposit $deposit,
        ReconcileCashDepositAction $action,
    ): JsonResponse {
        $this->authorize('reconcile', Payment::class);

        return ApiResponse::data(
            new CashDepositResource($action->handle($deposit, $this->actor($request))),
        );
    }
}

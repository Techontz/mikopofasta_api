<?php

declare(strict_types=1);

namespace App\Http\Controllers\Repayments;

use App\Domain\Ledger\Services\SystemActor;
use App\Domain\Organization\Services\BranchScope;
use App\Domain\Organization\Services\BranchScopeGuard;
use App\Domain\Repayments\Actions\ReceiveInboundPaymentAction;
use App\Domain\Repayments\Actions\RecordCashPaymentAction;
use App\Domain\Repayments\Actions\RecordRepaymentAction;
use App\Domain\Repayments\Actions\ResolveSuspenseAction;
use App\Domain\Repayments\Actions\RunOverdueProcessAction;
use App\Domain\Repayments\Enums\PaymentChannel;
use App\Domain\Repayments\Enums\SuspenseStatus;
use App\Domain\Repayments\Enums\TriggeredBy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Repayments\AllocateSuspenseRequest;
use App\Http\Requests\Repayments\CashPaymentRequest;
use App\Http\Requests\Repayments\InboundPaymentRequest;
use App\Http\Requests\Repayments\IndexPaymentRequest;
use App\Http\Requests\Repayments\UnmatchedPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\SuspenseItemResource;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\SuspenseItem;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Repayments and collections — §15.3.
 *
 * All three intake channels land here and all three funnel into
 * RecordRepaymentAction, which is the only place allocation and posting
 * happen (§7).
 */
final class PaymentController extends Controller
{
    public function __construct(
        private readonly BranchScope $scope,
        private readonly BranchScopeGuard $guard,
        private readonly SystemActor $system,
    ) {}

    /**
     * GET /api/v1/payments
     */
    public function index(IndexPaymentRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $filters = $request->validated();

        $query = Payment::query()
            ->with(['loan', 'journalEntry'])
            ->when(
                isset($filters['search']) && $filters['search'] !== '',
                fn ($q) => $q->where(function ($q) use ($filters): void {
                    $term = '%'.$filters['search'].'%';
                    $q->where('payment_reference', 'like', $term)
                        ->orWhere('transaction_id', 'like', $term)
                        // The loan's own two identifiers as well as the
                        // payment's: a customer chasing a payment quotes the
                        // reference they were given, not the payment's.
                        ->orWhereHas('loan', fn ($l) => $l->where('loan_number', 'like', $term)
                            ->orWhere('loans.payment_reference', 'like', $term));
                }),
            )
            ->when(! empty($filters['status']), fn ($q) => $q->whereIn('status', $filters['status']))
            ->when(! empty($filters['channel']), fn ($q) => $q->whereIn('channel', $filters['channel']))
            ->when(isset($filters['loan_id']), fn ($q) => $q->where('loan_id', $filters['loan_id']))
            /*
             * A payment is received against a loan, not against a person, so
             * there is no customer column to filter on. Asking through the loan
             * is what lets a customer's whole statement be one request: the
             * teller session used to fetch payments once per loan the customer
             * held, which is a request count that grows with their borrowing
             * history for a screen that only ever shows one list.
             */
            ->when(
                isset($filters['customer_id']),
                fn ($q) => $q->whereHas('loan', fn ($l) => $l->where('customer_id', $filters['customer_id'])),
            )
            ->when(isset($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->latest('received_at');

        /*
         * Unmatched money has no branch yet, so a branch-scoped user would
         * never see it. Suspense is Finance's queue and Finance holds
         * branches.view_all, so nothing is hidden from the people who resolve
         * it — but a branch officer legitimately should not see another
         * branch's receipts.
         */
        $query = $this->scope->applyToColumn($query, $this->actor($request));

        return ApiResponse::paginated(
            $query->paginate(ApiResponse::perPage($request->query('per_page')))->withQueryString(),
            PaymentResource::class,
        );
    }

    /**
     * GET /api/v1/payments/{payment}
     */
    public function show(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);
        $this->guard->authorizeBranchId($this->actor($request), $payment->branch_id, Payment::class);

        return ApiResponse::data(new PaymentResource(
            $payment->load(['loan', 'allocations', 'journalEntry']),
        ));
    }

    /**
     * POST /api/v1/payments/cash — the Teller's only write (§14).
     */
    public function cash(CashPaymentRequest $request, RecordCashPaymentAction $action): JsonResponse
    {
        $this->authorize('recordCash', Payment::class);

        $teller = $this->actor($request);
        $loan = Loan::query()->with(['schedules', 'branch'])->findOrFail($request->validated('loanId'));

        $this->guard->authorizeBranchId($teller, $loan->branch_id, Payment::class);
        $this->assertRepayable($loan);

        $payment = $action->handle($loan, Money::of((string) $request->validated('amount')), $teller);

        return ApiResponse::data(
            new PaymentResource($payment->load(['allocations', 'journalEntry'])),
            status: Response::HTTP_CREATED,
        );
    }

    /**
     * POST /api/v1/payments/unmatched — logging money nobody can place (§15.3).
     */
    public function unmatched(UnmatchedPaymentRequest $request, RecordRepaymentAction $action): JsonResponse
    {
        $this->authorize('manage', Payment::class);

        $payment = $action->recordUnmatched(
            amount: Money::of((string) $request->validated('amount')),
            channel: PaymentChannel::from((string) $request->validated('channel')),
            transactionId: $request->validated('transactionId'),
            reason: (string) $request->validated('reason'),
            actor: $this->actor($request),
            branchId: $request->validated('branchId'),
        );

        return ApiResponse::data(
            new PaymentResource($payment->load('suspenseItem')),
            status: Response::HTTP_CREATED,
        );
    }

    /**
     * GET /api/v1/payments/suspense — the unresolved queue (§15.3).
     */
    public function suspense(Request $request): JsonResponse
    {
        $this->authorize('manage', Payment::class);

        $items = SuspenseItem::query()
            ->with(['payment', 'resolver'])
            ->whereIn('status', [SuspenseStatus::Unallocated, SuspenseStatus::Investigating])
            ->latest('id')
            ->get();

        return ApiResponse::data(
            SuspenseItemResource::collection($items),
            ['total' => $items->count()],
        );
    }

    /**
     * POST /api/v1/payments/suspense/{item}/allocate — §15.3.
     */
    public function allocateSuspense(
        AllocateSuspenseRequest $request,
        SuspenseItem $item,
        ResolveSuspenseAction $action,
    ): JsonResponse {
        $this->authorize('manage', Payment::class);

        $loan = Loan::query()->with(['schedules', 'branch'])->findOrFail($request->validated('loanId'));

        $resolved = $action->allocate($item->load('payment'), $loan, $this->actor($request));

        return ApiResponse::data(new SuspenseItemResource($resolved->load(['payment', 'resolver'])));
    }

    /**
     * POST /api/v1/payments/suspense/{item}/investigate
     */
    public function investigateSuspense(Request $request, SuspenseItem $item, ResolveSuspenseAction $action): JsonResponse
    {
        $this->authorize('manage', Payment::class);

        return ApiResponse::data(
            new SuspenseItemResource($action->markInvestigating($item, $this->actor($request))),
        );
    }

    /**
     * POST /api/v1/loans/overdue/process — §15.3.
     *
     * Cron-triggered in production; also manually invokable by Finance, which
     * is why it is an endpoint at all.
     */
    public function runOverdueProcess(Request $request, RunOverdueProcessAction $action): JsonResponse
    {
        $this->authorize('manage', Payment::class);

        $run = $action->handle(TriggeredBy::Manual, $this->actor($request));

        return ApiResponse::data([
            'runDate' => $run->run_date->toDateString(),
            'loansProcessed' => $run->loans_processed,
            'installmentsPenalised' => $run->installments_penalised,
            'totalPenaltyApplied' => $run->total_penalty_applied,

            // Stated in the response, not just in a comment: the absence of a
            // ledger entry here is a documented decision (OSC-1), not an
            // oversight for a reader to wonder about.
            'ledgerPosting' => 'none — penalty income is recognised on collection (OSC-1)',
        ]);
    }

    /**
     * The provider webhook (§15.3). Called from routes/webhooks.php.
     *
     * §15.3 is precise about the responses: an unmatched payment still returns
     * 200, because it was successfully received and ledgered to Suspense — it
     * is unmatched, not failed. Only a duplicate is an error.
     */
    public function webhook(InboundPaymentRequest $request, ReceiveInboundPaymentAction $action): JsonResponse
    {
        /*
         * The webhook is not a person. It used to be attributed to "whichever
         * user happens to have the lowest id", which put a real employee's name
         * against every provider callback in the system. Client Decision 4
         * settled it: automated work runs as the dedicated System account.
         */
        $systemUser = $this->system->resolve();

        $result = $action->handle(
            reference: (string) $request->validated('reference'),
            amount: Money::of((string) $request->validated('amount')),
            channel: PaymentChannel::from((string) $request->validated('channel')),
            transactionId: (string) $request->validated('transactionId'),
            phone: $request->validated('phone'),
            actor: $systemUser,
        );

        $payment = $result['payment'];

        return ApiResponse::data([
            'paymentId' => (string) $payment->getKey(),
            'paymentReference' => $payment->payment_reference,
            'status' => $payment->status->value,
            'allocation' => $payment->allocations->map(fn ($a): array => [
                'loanScheduleId' => (string) $a->loan_schedule_id,
                'penalty' => $a->penalty_allocated,
                'interest' => $a->interest_allocated,
                'principal' => $a->principal_allocated,
            ])->all(),
        ]);
    }

    private function assertRepayable(Loan $loan): void
    {
        if (! $loan->status->isOpenBook()) {
            throw \App\Domain\Repayments\Exceptions\PaymentStateException::loanNotRepayable(
                $loan->loan_number,
                $loan->status->value,
            );
        }
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Enums\LoanStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Payments\AllocateSuspenseRequest;
use App\Http\Requests\Api\Payments\ConfirmedPaymentRequest;
use App\Http\Requests\Api\Payments\ReasonRequest;
use App\Http\Requests\Api\Payments\UnmatchedPaymentRequest;
use App\Http\Resources\Api\V1\Payments\PaymentResource;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\Penalty;
use App\Models\SalaryAdvance;
use App\Services\AccessControl;
use App\Services\LoanRecoveryService;
use App\Services\LoanService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payments → Suspense Account: unmatched and overpaid money. Finance views it, searches the customer,
 * confirms ownership and allocates (Documents: POST /payments/allocate), flags or refunds it.
 */
class SuspenseController extends ApiController
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly LoanService $loans,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('payments.suspense');

        $status = $request->input('status', 'suspense');
        $query = $this->scoped(Payment::query())
            ->when($status === 'suspense', fn ($query) => $query->whereIn('status', PaymentStatus::values(...PaymentStatus::suspense())))
            ->when($status === 'direct', fn ($query) => $query->where('source', Payment::SOURCE_WEBHOOK))
            ->when(! in_array($status, ['suspense', 'direct', 'all'], true), fn ($query) => $query->where('status', $status))
            ->when($status === 'all', fn ($query) => $query->where(fn ($inner) => $inner->where('source', '!=', Payment::SOURCE_TELLER)->orWhereNotNull('parent_id')))
            ->with(['customer', 'branch', 'employee', 'loan', 'parent', 'verifier', 'allocations.loan', 'allocations.loanTransaction'])
            ->latest('paid_on')
            ->latest('id');
        $this->applyFilters($query, $request, 'paid_on');

        $rows = $query->get();

        return response()->json([
            'data' => PaymentResource::collection($rows),
            'suspense_balance' => round((float) $this->scoped(Payment::query())->whereIn('status', PaymentStatus::values(...PaymentStatus::suspense()))->get()->sum('unallocated_amount'), 2),
        ]);
    }

    public function store(UnmatchedPaymentRequest $request): JsonResponse
    {
        $this->authorizeAny('payments.suspense');
        if ($request->filled('branch_id')) {
            $this->assertBranchAccessible($request->integer('branch_id'));
        }

        $payment = $this->payments->recordUnmatched($this->currentEmployee()->company_id, $request->validated(), $this->currentEmployee());

        return $this->message('Payment saved to suspense successfully', 201, ['data' => new PaymentResource($payment)]);
    }

    /**
     * POST /payments/confirmed — Finance-entered payment, single step (C6): CONFIRMED, received into suspense and allocated to
     * the loan (repayment, or recovery for a written-off loan) in one transaction.
     */
    public function storeConfirmed(ConfirmedPaymentRequest $request): JsonResponse
    {
        $this->authorizeAny('payments.suspense');
        $loan = Loan::findOrFail($request->integer('loan_id'));
        $this->assertBranchAccessible((int) $loan->branch_id);

        $payment = $this->payments->recordConfirmed($loan, $request->validated(), $this->currentEmployee());

        return $this->message($loan->status === LoanStatus::WrittenOff ? 'Payment confirmed and recorded as write-off recovery' : 'Payment confirmed and allocated successfully', 201, [
            'data' => new PaymentResource($payment->load(['customer', 'branch', 'employee', 'loan', 'verifier', 'allocations.loan', 'allocations.loanTransaction'])),
        ]);
    }

    public function allocate(AllocateSuspenseRequest $request, Payment $payment): JsonResponse
    {
        $this->authorizeAny('payments.suspense');
        $this->assertPaymentAccessible($payment);
        $loan = Loan::findOrFail($request->integer('loan_id'));
        $this->assertBranchAccessible((int) $loan->branch_id);

        $this->payments->allocateSuspense($payment, $loan, (float) $request->input('amount'), $this->currentEmployee());

        return $this->message($loan->status === LoanStatus::WrittenOff ? 'Payment allocated successfully and recorded as write-off recovery (principal → penalty → interest → insurance)' : 'Payment allocated successfully');
    }

    public function flag(ReasonRequest $request, Payment $payment): JsonResponse
    {
        $this->authorizeAny('payments.suspense');
        $this->assertPaymentAccessible($payment);

        $payment = $this->payments->flag($payment, $request->string('reason')->toString());

        return $this->message($payment->status === PaymentStatus::Flagged ? 'Payment flagged successfully' : 'Flag removed successfully');
    }

    public function refund(ReasonRequest $request, Payment $payment): JsonResponse
    {
        $this->authorizeAny('payments.suspense');
        $this->assertPaymentAccessible($payment);

        $this->payments->refund($payment, $request->string('reason')->toString(), $this->currentEmployee());

        return $this->message('Payment refunded successfully');
    }

    /**
     * Repayable loans as {value,label} with outstanding balance, and written-off loans with an unrecovered write-off balance whose
     * component split is known (money allocated to them is recorded as a write-off recovery, Principal → Penalty → Interest →
     * Insurance), for the allocation and confirmed-payment modals.
     */
    public function loanOptions(Request $request, LoanRecoveryService $recoveries): JsonResponse
    {
        $this->authorizeAny('payments.suspense');

        $loans = $this->scoped(Loan::query())
            ->whereIn('status', [...LoanStatus::values(...LoanStatus::repayable()), LoanStatus::WrittenOff->value])
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', $request->integer('customer_id')))
            ->with('customer')
            ->latest('id')
            ->limit(500)
            ->get();

        // The customer's other debts, shown beside the loan: penalties standing on their own (carried over from the old
        // system) and salary advances, which a loan payment never touches.
        $customerIds = $loans->pluck('customer_id')->unique()->values();
        $loanlessPenalties = Penalty::query()->whereIn('customer_id', $customerIds)->whereNull('loan_id')->where('is_waived', false)
            ->groupBy('customer_id')->selectRaw('customer_id, SUM(amount - paid_amount) AS unpaid')->pluck('unpaid', 'customer_id');
        $advances = SalaryAdvance::query()->whereIn('customer_id', $customerIds)->where('status', 'active')->whereNull('reversed_at')->withSum('payments', 'amount')->get()
            ->groupBy('customer_id')->map(fn ($items): float => round((float) $items->sum(fn (SalaryAdvance $advance): float => $advance->remaining_amount), 2));
        $otherDebt = fn (Loan $loan): array => [
            'penalty_without_loan' => round((float) ($loanlessPenalties[$loan->customer_id] ?? 0), 2),
            'salary_advance' => (float) ($advances[$loan->customer_id] ?? 0),
        ];

        return response()->json(['data' => $loans->map(function (Loan $loan) use ($recoveries, $otherDebt): ?array {
            if ($loan->status === LoanStatus::WrittenOff) {
                $position = $recoveries->position($loan);
                if ($position['unrecovered'] <= 0.004 || $position['components_status'] === LoanRecoveryService::COMPONENTS_AMBIGUOUS) {
                    return null;
                }

                return [
                    'value' => (string) $loan->id,
                    'label' => $loan->customer->full_name.' / '.($loan->reference_number ?? $loan->loan_number).' — WRITTEN OFF, unrecovered '.money($position['unrecovered']),
                    'customer_id' => $loan->customer_id,
                    'phone' => $loan->customer->phone,
                    'written_off' => true,
                    'recovery' => $position,
                    'outstanding' => [...array_map(fn (array $component): float => $component['remaining'], $position['components']), 'total' => $position['unrecovered']],
                ];
            }

            $outstanding = $this->loans->outstanding($loan);

            return [
                'value' => (string) $loan->id,
                'label' => $loan->customer->full_name.' / '.($loan->reference_number ?? $loan->loan_number).($loan->is_legacy_opening ? ' (OLD SYSTEM)' : '').' — '.money($outstanding['total']),
                'customer_id' => $loan->customer_id,
                'phone' => $loan->customer->phone,
                'written_off' => false,
                'is_legacy_opening' => $loan->is_legacy_opening,
                'outstanding' => $outstanding,
                'other_debt' => $otherDebt($loan),
            ];
        })->filter()->values()]);
    }

    private function assertPaymentAccessible(Payment $payment): void
    {
        if ($payment->branch_id === null) {
            abort_unless(app(AccessControl::class)->branchIds($this->currentEmployee()) === null, 403, 'You do not have access to this branch.');

            return;
        }

        $this->assertBranchAccessible((int) $payment->branch_id);
    }
}

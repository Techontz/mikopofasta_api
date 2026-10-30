<?php

namespace App\Http\Controllers\Api\V1\Loans;

use App\Models\Loan;
use App\Models\LoanDiscount;
use App\Services\LoanDiscounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Loan Repayment → Discount / Waiver ({@see LoanDiscounts}): requested by loans.discount_request, decided by
 * loans.discount_approve (never the requester). Lists follow the employee's branch scope and the branch/date filters.
 */
class LoanDiscountController extends LoanApiController
{
    public function __construct(private LoanDiscounts $discounts) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('loans.discount_request', 'loans.discount_approve');

        $status = $request->input('status', LoanDiscount::STATUS_PENDING);
        $query = $this->scoped(LoanDiscount::query())
            ->with(['loan:id,loan_number,reference_number', 'customer:id,first_name,middle_name,last_name,phone', 'branch:id,name', 'requester:id,first_name,last_name', 'decider:id,first_name,last_name'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($request->filled('loan_id'), fn ($query) => $query->where('loan_id', $request->integer('loan_id')))
            ->latest('id');
        // Pending requests stay listed until decided; decided ones follow the date filter on the decision day.
        $this->applyFilters($query, $request, $status === LoanDiscount::STATUS_PENDING ? null : 'loan_discounts.decided_at');
        $rows = $query->get();

        return response()->json([
            'data' => $rows->map(fn (LoanDiscount $discount): array => $this->present($discount))->all(),
            'totals' => [
                'count' => $rows->count(),
                'requested' => round((float) $rows->sum('requested_amount'), 2),
                'approved' => round((float) $rows->sum('approved_amount'), 2),
            ],
        ]);
    }

    public function store(Request $request, Loan $loan): JsonResponse
    {
        $this->authorizeAny('loans.discount_request');
        $this->ensureVisible($loan);
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'actual_payment' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $discount = $this->discounts->request($loan, (float) $validated['amount'], (float) ($validated['actual_payment'] ?? 0), $validated['reason'], $this->currentEmployee());

        return response()->json(['message' => 'Discount request sent for approval. The balance changes only once it is approved.', 'data' => $this->present($discount->load(['loan', 'customer', 'branch']))], 201);
    }

    public function approve(Request $request, LoanDiscount $discount): JsonResponse
    {
        $this->authorizeAny('loans.discount_approve');
        $this->ensureVisible($discount->loan);
        $validated = $request->validate([
            'approved_amount' => ['nullable', 'numeric', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $discount = $this->discounts->approve($discount, $this->currentEmployee(), isset($validated['approved_amount']) ? (float) $validated['approved_amount'] : null, $validated['note'] ?? null);

        return response()->json(['message' => 'Discount approved and applied to the loan.', 'data' => $this->present($discount->load(['loan', 'customer', 'branch']))]);
    }

    public function reject(Request $request, LoanDiscount $discount): JsonResponse
    {
        $this->authorizeAny('loans.discount_approve');
        $this->ensureVisible($discount->loan);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return response()->json(['message' => 'Discount request rejected.', 'data' => $this->present($this->discounts->reject($discount, $this->currentEmployee(), $validated['reason']))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(LoanDiscount $discount): array
    {
        $customer = $discount->customer;

        return [
            'id' => $discount->id,
            'status' => $discount->status,
            'loan_id' => $discount->loan_id,
            'loan_number' => $discount->loan?->loan_number,
            'reference_number' => $discount->loan?->reference_number,
            'customer_name' => $customer ? trim(implode(' ', array_filter([$customer->first_name, $customer->middle_name, $customer->last_name]))) : null,
            'branch' => $discount->branch?->name,
            'original_debt' => (float) $discount->original_debt,
            'actual_payment' => (float) $discount->actual_payment,
            'requested_amount' => (float) $discount->requested_amount,
            'approved_amount' => $discount->approved_amount === null ? null : (float) $discount->approved_amount,
            'principal' => (float) $discount->principal,
            'interest' => (float) $discount->interest,
            'insurance' => (float) $discount->insurance,
            'reason' => $discount->reason,
            'requested_by' => $discount->requester ? trim($discount->requester->first_name.' '.$discount->requester->last_name) : null,
            'requested_at' => $discount->created_at?->toDateTimeString(),
            'decided_by' => $discount->decider ? trim($discount->decider->first_name.' '.$discount->decider->last_name) : null,
            'decided_at' => $discount->decided_at?->toDateTimeString(),
            'decision_note' => $discount->decision_note,
            'journal_entry_id' => $discount->journal_entry_id,
        ];
    }
}

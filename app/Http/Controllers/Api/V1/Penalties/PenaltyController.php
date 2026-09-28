<?php

namespace App\Http\Controllers\Api\V1\Penalties;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Loans\LoanWorkflowController;
use App\Http\Requests\Api\Payments\PayPenaltyRequest;
use App\Models\Penalty;
use App\Models\PenaltyPayment;
use App\Services\LoanService;
use App\Services\ReversalRequests;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Penalty → Penalty List (live admin/get_penart_list) and Paid Penalty (live admin/penart_paid_list).
 */
class PenaltyController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('penalties.manage');
        $this->normaliseBranchFilter($request);

        $query = $this->scoped(Penalty::query())
            ->where('is_waived', false)
            ->whereColumn('paid_amount', '<', 'amount')
            ->with(['customer', 'branch', 'loan', 'accrualJournal:id,reference', 'legacyImportRow:id,loan_amount'])
            ->orderBy('penalty_date');
        $this->applyFilters($query, $request);

        return response()->json(['data' => $query->get()->map(fn (Penalty $penalty): array => [
            'id' => $penalty->id,
            'customer_id' => $penalty->customer_id,
            'customer' => $penalty->customer?->full_name,
            'branch_id' => $penalty->branch_id,
            'branch' => $penalty->branch?->name,
            'loan_id' => $penalty->loan_id,
            // A penalty carried over from the old system may have no loan here; the Loan Amount its Penalty List printed is shown.
            'loan_amount' => (float) ($penalty->loan?->total_payable ?? $penalty->legacyImportRow?->loan_amount ?? 0),
            'is_legacy_opening' => $penalty->is_legacy_opening,
            'amount' => (float) $penalty->amount,
            'paid_amount' => (float) $penalty->paid_amount,
            'remaining' => round((float) $penalty->amount - (float) $penalty->paid_amount, 2),
            'penalty_date' => $penalty->penalty_date->toDateString(),
            'accounting' => $penalty->accrual_journal_entry_id !== null ? 'accrued' : 'cash',
            'accrual_reference' => $penalty->accrualJournal?->reference,
        ])]);
    }

    public function pay(PayPenaltyRequest $request, Penalty $penalty, LoanService $loans): JsonResponse
    {
        $this->authorizeAny('penalties.manage');
        $this->assertBranchAccessible((int) $penalty->branch_id);

        $amount = (float) $request->input('penart_paid');
        $remaining = round((float) $penalty->amount - (float) $penalty->paid_amount, 2);

        if ($penalty->is_waived || $remaining <= 0) {
            throw ValidationException::withMessages(['penart_paid' => 'Penalty is already cleared']);
        }
        if ($amount > $remaining + 0.001) {
            throw ValidationException::withMessages(['penart_paid' => 'Amount is greater than penalty amount ('.money($remaining).')']);
        }

        $loans->payPenalty($penalty, $amount, CarbonImmutable::today(), $this->currentEmployee());

        return $this->message('Penalty Paid successfully');
    }

    /**
     * Live "aporojize_penalty" (trash icon): the penalty is forgiven, recorded in the audit trail. Cash-basis penalties post
     * nothing (rule 14); only a legacy accrued penalty's unpaid remainder is reversed out of income ({@see LoanService::waivePenalty()}).
     */
    public function waive(Penalty $penalty, LoanService $loans): JsonResponse
    {
        $this->authorizeAny('penalties.manage');
        $this->assertBranchAccessible((int) $penalty->branch_id);

        $loans->waivePenalty($penalty, $this->currentEmployee());

        return $this->message('Penalty Removed successfully');
    }

    /**
     * POST /penalties/payments/{penaltyPayment}/reverse — REQUEST the reversal of a direct penalty payment (maker/checker).
     * Nothing is posted until another Finance user, an Admin or the Super Admin approves it under Reversal Requests.
     */
    public function reversePayment(Request $request, PenaltyPayment $penaltyPayment, ReversalRequests $reversals): JsonResponse
    {
        $this->authorizeAny('penalties.reverse_payment');
        $penalty = $penaltyPayment->penalty()->firstOrFail();
        abort_unless((int) $penalty->company_id === (int) $this->currentEmployee()->company_id, 404);
        $this->assertBranchAccessible((int) $penalty->branch_id);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $reversal = $reversals->requestPenaltyPayment($penaltyPayment, $validated['reason'], $this->currentEmployee());

        return $this->message(LoanWorkflowController::REVERSAL_REQUESTED, 201, ['reversal_request' => ['id' => $reversal->id, 'status' => $reversal->status]]);
    }

    public function paid(Request $request, LoanService $loans, ReversalRequests $reversals): JsonResponse
    {
        $this->authorizeAny('penalties.manage');
        $viewer = $this->currentEmployee();
        $mayRequest = $viewer->can('penalties.reverse_payment');
        $mayApprove = $viewer->can('reversals.approve');
        $this->normaliseBranchFilter($request);

        $query = PenaltyPayment::query()
            ->whereHas('penalty', function ($penalty) use ($request): void {
                $this->scoped($penalty);
                $this->applyFilters($penalty, $request);
            })
            ->with(['penalty.customer', 'penalty.branch', 'penalty.loan', 'journalEntry', 'reverser', 'reversalJournalEntry'])
            ->latest('paid_on')
            ->latest('id')
            ->when($request->filled('from'), fn ($payments) => $payments->whereDate('paid_on', '>=', $request->date('from')->toDateString()))
            ->when($request->filled('to'), fn ($payments) => $payments->whereDate('paid_on', '<=', $request->date('to')->toDateString()));

        return response()->json(['data' => $query->get()->map(function (PenaltyPayment $payment) use ($loans, $reversals, $viewer, $mayRequest, $mayApprove): array {
            $blocker = $mayRequest && $payment->reversed_at === null ? $loans->penaltyPaymentReverseBlockedReason($payment, $viewer) : null;
            $pending = $payment->reversed_at === null ? $reversals->pendingFor($payment) : null;

            return [
                'id' => $payment->id,
                'penalty_id' => $payment->penalty_id,
                'loan_id' => $payment->penalty?->loan_id,
                'loan_number' => $payment->penalty?->loan?->loan_number,
                'customer' => $payment->penalty?->customer?->full_name,
                'branch' => $payment->penalty?->branch?->name,
                'amount' => (float) $payment->amount,
                'paid_on' => $payment->paid_on->toDateString(),
                'source' => $payment->isDirect() ? 'direct' : 'repayment',
                'accounting' => $payment->penalty?->accrual_journal_entry_id !== null ? 'accrued' : 'cash',
                'journal_reference' => $payment->journalEntry?->reference,
                'reversed' => $payment->reversed_at !== null,
                'reversed_at' => $payment->reversed_at?->toDateTimeString(),
                'reversed_by' => $payment->reverser?->full_name,
                'reversal_reason' => $payment->reversal_reason,
                'reversal_reference' => $payment->reversalJournalEntry?->reference,
                'can_reverse' => $mayRequest && $payment->reversed_at === null && $blocker === null,
                'reverse_blocked_reason' => $blocker,
                'reversal_request' => $pending !== null ? $reversals->present($pending, $viewer, $mayApprove) : null,
            ];
        })]);
    }

    /**
     * The live filter modal posts "blanch_id"; the shared filter helper reads "branch_id".
     */
    private function normaliseBranchFilter(Request $request): void
    {
        if ($request->filled('blanch_id') && ! $request->filled('branch_id')) {
            $request->merge(['branch_id' => $request->input('blanch_id')]);
        }
        if ($request->filled('branch_id') && $request->input('branch_id') !== 'all') {
            $this->assertBranchAccessible($request->integer('branch_id'));
        }
    }
}

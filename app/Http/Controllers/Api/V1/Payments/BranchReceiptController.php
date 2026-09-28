<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Payments\BranchReceiptRequest;
use App\Http\Requests\Api\Payments\ReasonRequest;
use App\Http\Resources\Api\V1\Payments\PaymentResource;
use App\Models\ApprovalPolicy;
use App\Models\Loan;
use App\Models\Payment;
use App\Services\AccessControl;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Payments → Branch receipts (C6): non-cash money a branch / teller takes (mobile money, bank) is recorded PENDING_APPROVAL with
 * no posting; Finance (payments.verify) approves — received into suspense and allocated to the loan (repayment, or recovery for a
 * written-off loan) — or rejects. The employee who recorded a receipt cannot approve or reject it (rule 6).
 */
class BranchReceiptController extends ApiController
{
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * GET /payments/branch-receipts?status=pending_approval|all|… — a teller without payments.verify sees their own receipts.
     */
    public function index(Request $request, SegregationOfDuties $duties): JsonResponse
    {
        $this->authorizeAny('payments.verify', 'payments.cash');
        $viewer = $this->currentEmployee();
        $canApprove = $viewer->can('payments.verify');
        $status = $request->string('status', PaymentStatus::PendingApproval->value)->toString();

        $query = $this->scoped(Payment::query())
            ->where('source', Payment::SOURCE_TELLER)
            ->where('channel', '!=', 'CASH')
            ->whereNotIn('status', [PaymentStatus::PendingVerification->value, PaymentStatus::Deposited->value])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when(! $canApprove && app(AccessControl::class)->branchIds($viewer) !== null, fn ($query) => $query->where('employee_id', $viewer->id))
            ->with(['customer', 'branch', 'employee', 'loan', 'verifier', 'bankAccount'])
            ->latest('paid_on')
            ->latest('id');
        $this->applyFilters($query, $request, 'paid_on');
        $rows = $query->get();

        return response()->json([
            'data' => $rows->map(fn (Payment $payment): array => (new PaymentResource($payment))->resolve($request) + [
                'bank_account' => $payment->bankAccount?->name,
                ...$duties->flags($payment->employee_id, $viewer, $payment->status === PaymentStatus::PendingApproval, $canApprove, workflow: ApprovalPolicy::BRANCH_RECEIPTS),
            ])->values(),
            'pending_total' => round((float) $rows->where('status', PaymentStatus::PendingApproval)->sum('amount'), 2),
        ]);
    }

    /**
     * POST /payments/branch-receipts — teller records a non-cash receipt pending Finance approval.
     */
    public function store(BranchReceiptRequest $request): JsonResponse
    {
        $this->authorizeAny('payments.cash', 'loans.recover');
        $loan = Loan::findOrFail($request->integer('loan_id'));
        $this->assertBranchAccessible((int) $loan->branch_id);

        $payment = $this->payments->recordBranchReceipt($loan, $request->validated(), $this->currentEmployee());

        return $this->message('Receipt recorded and sent to Finance for approval', 201, [
            'data' => new PaymentResource($payment->load(['customer', 'branch', 'employee', 'loan'])),
        ]);
    }

    /**
     * POST /payments/branch-receipts/{payment}/approve — optional bank_account_id (else the receipt's bank or bank clearing).
     */
    public function approve(Request $request, Payment $payment): JsonResponse
    {
        $this->authorizeAny('payments.verify');
        $this->assertReceiptAccessible($payment);
        $validated = $request->validate(['bank_account_id' => ['nullable', 'integer', Rule::exists('bank_accounts', 'id')->where('company_id', $this->currentEmployee()->company_id)]]);

        $payment = $this->payments->approveBranchReceipt($payment, $this->currentEmployee(), isset($validated['bank_account_id']) ? (int) $validated['bank_account_id'] : null);

        return $this->message($payment->status === PaymentStatus::Allocated ? 'Receipt approved and posted to the loan' : 'Receipt approved; TZS '.money($payment->unallocated_amount).' is held in suspense', 200, [
            'data' => new PaymentResource($payment->load(['customer', 'branch', 'employee', 'loan', 'verifier'])),
        ]);
    }

    /**
     * POST /payments/branch-receipts/{payment}/reject — nothing is posted.
     */
    public function reject(ReasonRequest $request, Payment $payment): JsonResponse
    {
        $this->authorizeAny('payments.verify');
        $this->assertReceiptAccessible($payment);

        $this->payments->rejectBranchReceipt($payment, $request->string('reason')->toString(), $this->currentEmployee());

        return $this->message('Receipt rejected successfully');
    }

    private function assertReceiptAccessible(Payment $payment): void
    {
        abort_unless((int) $payment->company_id === (int) $this->currentEmployee()->company_id, 404);
        $this->assertBranchAccessible((int) $payment->branch_id);
    }
}

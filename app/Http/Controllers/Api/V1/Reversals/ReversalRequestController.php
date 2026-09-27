<?php

namespace App\Http\Controllers\Api\V1\Reversals;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Loans\LoanReasonRequest;
use App\Models\ReversalRequest;
use App\Services\ReversalRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Reversal Requests (maker/checker, user ruling 2026-09-17): the list of requested reversals of loan repayments, loan
 * disbursements and direct penalty payments, approved or rejected by another Finance user, an Admin or the Super Admin.
 */
class ReversalRequestController extends ApiController
{
    private const VIEW_PERMISSIONS = ['reversals.approve', 'loans.reverse_repayment', 'loans.reverse_disbursement', 'penalties.reverse_payment', 'salary_advance.manage'];

    public function __construct(private readonly ReversalRequests $requests) {}

    /**
     * GET /reversal-requests?status=pending|approved|rejected|all&type= — requests in scope with the viewer's approval flags.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny(...self::VIEW_PERMISSIONS);
        $status = $request->string('status', ReversalRequest::PENDING)->toString();
        $type = $request->string('type')->toString();
        $viewer = $this->currentEmployee();
        $mayApprove = Gate::allows('reversals.approve');

        $rows = $this->scoped(ReversalRequest::query())
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when(array_key_exists($type, ReversalRequest::TYPES), fn ($query) => $query->where('type', $type))
            ->with(['subject', 'loan.customer', 'branch', 'requester', 'approver', 'rejecter', 'reversalJournalEntry'])
            ->latest('id')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (ReversalRequest $row): array => $this->requests->present($row, $viewer, $mayApprove))->values(),
            'types' => ReversalRequest::TYPES,
        ]);
    }

    /**
     * POST /reversal-requests/{reversalRequest}/approve — post the reversal now, as the approver.
     */
    public function approve(int $reversalRequest): JsonResponse
    {
        $this->authorizeAny('reversals.approve');
        $row = $this->find($reversalRequest);

        $result = $this->requests->approve($row, $this->currentEmployee());
        $message = match ($row->type) {
            ReversalRequest::REPAYMENT => 'Repayment reversed successfully. TZS '.money($result['result']['transaction']->amount).' returned to suspense (receipt '.$result['result']['payment']->receipt_number.').'
                .($result['result']['closed_period'] !== null ? " The repayment belongs to the closed period {$result['result']['closed_period']}; the reversal was posted today as an adjustment in the current open period." : ''),
            ReversalRequest::DISBURSEMENT => 'Loan disbursement reversed successfully; the loan is cancelled.'
                .($row->loan?->topupOf !== null ? " The top-up settlement of loan {$row->loan->topupOf->loan_number} was reversed too; that loan is open again." : ''),
            ReversalRequest::SALARY_ADVANCE_PAYMENT => 'Salary advance deposit reversed successfully. TZS '.money($row->amount).' is owed on the salary advance again.',
            default => 'Penalty payment reversed successfully. TZS '.money($row->amount).' is owed on the penalty again.',
        };

        return $this->message($message, 200, ['data' => $this->requests->present($result['request']->fresh(), $this->currentEmployee(), true)]);
    }

    /**
     * POST /reversal-requests/{reversalRequest}/reject — nothing is posted. Approvers reject; the requester may withdraw.
     */
    public function reject(LoanReasonRequest $request, int $reversalRequest): JsonResponse
    {
        $this->authorizeAny(...self::VIEW_PERMISSIONS);
        $row = $this->find($reversalRequest);
        abort_unless(Gate::allows('reversals.approve') || (int) $row->requested_by === (int) $this->currentEmployee()->id, 403, 'You do not have permission to perform this action.');

        $rejected = $this->requests->reject($row, $request->string('reason')->toString(), $this->currentEmployee());

        return $this->message('Reversal request rejected; nothing was posted.', 200, ['data' => $this->requests->present($rejected, $this->currentEmployee(), Gate::allows('reversals.approve'))]);
    }

    private function find(int $id): ReversalRequest
    {
        return $this->scoped(ReversalRequest::query())->whereKey($id)->firstOrFail();
    }
}

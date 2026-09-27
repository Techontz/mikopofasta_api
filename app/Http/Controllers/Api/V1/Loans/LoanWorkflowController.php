<?php

namespace App\Http\Controllers\Api\V1\Loans;

use App\Enums\LoanStatus;
use App\Http\Requests\Api\Loans\DisbursementSourceRequest;
use App\Http\Requests\Api\Loans\EscalationRequest;
use App\Http\Requests\Api\Loans\LoanReasonRequest;
use App\Http\Requests\Api\Loans\MandateRequest;
use App\Http\Requests\Api\Loans\ReverseDisbursementRequest;
use App\Http\Requests\Api\Loans\ReverseRepaymentRequest;
use App\Http\Resources\Api\V1\Loans\LoanResource;
use App\Models\ApprovalPolicy;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\WriteOffRequest;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\LoanAgreement;
use App\Services\LoanService;
use App\Services\LoanWorkflow;
use App\Services\ReversalRequests;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Loan lifecycle actions (Documents: LOAN PROCESS OVERVIEW): manager approval, e-mandate + OTP, credit officer
 * review with Vodacom KYC, finance preparation, Vodacom disbursement with retries and escalation, closure,
 * write-off, comments and loan agreement upload.
 */
class LoanWorkflowController extends LoanApiController
{
    public const REVERSAL_REQUESTED = 'Reversal request submitted. Nothing is posted until another Finance user, an Admin or the Super Admin approves it under Reversal Requests.';

    public function __construct(private readonly LoanWorkflow $workflow) {}

    /**
     * POST /loans/{id}/approve-manager with the live "Approved Loan" field (loan_aprove).
     */
    public function approveManager(Request $request, Loan $loan): JsonResponse
    {
        $this->authorizeAny('loans.approve_manager');
        $this->ensureVisible($loan);
        $validated = $request->validate(['loan_aprove' => ['required', 'numeric', 'min:1']]);

        $loan = $this->workflow->approveByManager($loan, (float) $validated['loan_aprove'], $this->currentEmployee());

        return $this->loanMessage('Loan Approved successfully', $loan);
    }

    /**
     * Reject with reason — branch manager at approval, credit officer at credit review.
     */
    public function reject(LoanReasonRequest $request, Loan $loan): JsonResponse
    {
        $this->authorizeStage($loan);
        $loan = $this->workflow->reject($loan, $request->string('reason')->toString(), $this->currentEmployee());

        return $this->loanMessage('Loan Rejected successfully', $loan);
    }

    /**
     * Modify → back to the loan officer with a reason.
     */
    public function modify(LoanReasonRequest $request, Loan $loan): JsonResponse
    {
        $this->authorizeStage($loan);
        $loan = $this->workflow->returnForModification($loan, $request->string('reason')->toString(), $this->currentEmployee());

        return $this->loanMessage('Loan returned to loan officer for modification', $loan);
    }

    public function createMandate(MandateRequest $request, Loan $loan): JsonResponse
    {
        $this->authorizeAny('loans.apply', 'loans.approve_manager');
        $this->ensureVisible($loan);

        $result = $this->workflow->createMandate($loan, $request->validated(), $this->currentEmployee());

        return $this->loanMessage($result['message'], $loan->fresh(), $result['success'] ? 200 : 422);
    }

    public function verifyMandateOtp(Request $request, Loan $loan): JsonResponse
    {
        $this->authorizeAny('loans.apply', 'loans.approve_manager');
        $this->ensureVisible($loan);
        $validated = $request->validate(['otp' => ['required', 'digits:6']]);

        $result = $this->workflow->verifyMandateOtp($loan, $validated['otp'], $this->currentEmployee());

        return $this->loanMessage($result['message'], $loan->fresh(), $result['success'] ? 200 : 422);
    }

    /**
     * POST /vodacom/kyc-verify for the loan's customer.
     */
    public function verifyTelco(Loan $loan): JsonResponse
    {
        $this->authorizeAny('loans.credit_review');
        $this->ensureVisible($loan);

        $result = $this->workflow->verifyTelco($loan, $this->currentEmployee());

        return $this->loanMessage($result['message'], $loan->fresh(), 200, ['verification' => $result]);
    }

    public function approveCredit(Loan $loan): JsonResponse
    {
        $this->authorizeAny('loans.credit_review');
        $this->ensureVisible($loan);

        $loan = $this->workflow->approveCredit($loan, $this->currentEmployee());

        return $this->loanMessage('Loan Approved successfully. Reference number: '.$loan->reference_number, $loan);
    }

    /**
     * Finance prepares the batch and chooses the disbursement source (branch cash or a company bank account).
     */
    public function prepareDisbursement(DisbursementSourceRequest $request, Loan $loan): JsonResponse
    {
        $this->authorizeAny('loans.prepare_disbursement');
        $this->ensureVisible($loan);

        $disbursement = $this->workflow->prepareDisbursement($loan, $this->currentEmployee(), $request->source());

        return $this->loanMessage('Disbursement prepared, batch '.$disbursement->batch_id.' from '.$disbursement->sourceLabel(), $loan->fresh());
    }

    /**
     * Accounts the loan can be disbursed from, with balances and the amount required from each.
     */
    public function disbursementSources(Loan $loan): JsonResponse
    {
        $this->authorizeAny('loans.prepare_disbursement', 'loans.disburse');
        $this->ensureVisible($loan);

        return response()->json(['data' => $this->workflow->sourceOptions($loan)]);
    }

    /**
     * Finance "Disburse" → request to Vodacom; the page opens the Vodacom portal when configured.
     */
    public function disburse(DisbursementSourceRequest $request, Loan $loan): JsonResponse
    {
        $this->authorizeAny('loans.disburse');
        $this->ensureVisible($loan);

        $result = $this->workflow->requestDisbursement($loan, $this->currentEmployee(), $request->source());

        return $this->loanMessage($result['message'], $loan->fresh(), $result['status'] === LoanStatus::Active || $result['status'] === LoanStatus::AwaitingDisbursement ? 200 : 422, [
            'batch_id' => $result['batch_id'],
            'portal_url' => $result['portal_url'],
        ]);
    }

    public function retry(DisbursementSourceRequest $request, Loan $loan): JsonResponse
    {
        $this->authorizeAny('loans.disburse');
        $this->ensureVisible($loan);

        $result = $this->workflow->retryDisbursement($loan, $this->currentEmployee(), $request->source());

        return $this->loanMessage($result['message'], $loan->fresh(), $result['status'] === LoanStatus::Active || $result['status'] === LoanStatus::AwaitingDisbursement ? 200 : 422, [
            'batch_id' => $result['batch_id'],
            'portal_url' => $result['portal_url'],
        ]);
    }

    public function escalation(EscalationRequest $request, Loan $loan): JsonResponse
    {
        $this->authorizeAny('loans.disburse');
        $this->ensureVisible($loan);

        $loan = $this->workflow->resolveEscalation($loan, $request->string('action')->toString(), $request->input('channel'), $request->string('reason')->toString(), $this->currentEmployee(), $request->source());

        return $this->loanMessage(match ($loan->status) {
            LoanStatus::Cancelled => 'Loan Cancelled successfully',
            LoanStatus::DisbursementSuspense => 'Loan moved to suspense successfully',
            default => 'Disbursement moved to '.strtoupper((string) $loan->disbursement_channel).' successfully',
        }, $loan);
    }

    public function requeue(Loan $loan): JsonResponse
    {
        $this->authorizeAny('loans.disburse', 'loans.prepare_disbursement');
        $this->ensureVisible($loan);

        return $this->loanMessage('Loan sent back to Finance successfully', $this->workflow->requeue($loan, $this->currentEmployee()));
    }

    public function confirmDisbursement(Request $request, Loan $loan): JsonResponse
    {
        $this->authorizeAny('loans.disburse');
        $this->ensureVisible($loan);
        $validated = $request->validate(['reference' => ['required', 'string', 'max:100']]);

        return $this->loanMessage('Loan Disbursed successfully', $this->workflow->confirmManualDisbursement($loan, $validated['reference'], $this->currentEmployee()));
    }

    /**
     * Branch cash-out with the SMS withdrawal code (cash channel chosen for an escalated disbursement).
     */
    public function cashOut(Request $request, Loan $loan): JsonResponse
    {
        $this->authorizeAny('payments.cash');
        $this->ensureVisible($loan);
        $validated = $request->validate(['code' => ['required', 'digits_between:4,6']]);

        return $this->loanMessage('Withdrawal successfully', $this->workflow->cashOut($loan, (string) $validated['code'], $this->currentEmployee()));
    }

    public function close(Loan $loan): JsonResponse
    {
        $this->authorizeAny('loans.approve_manager', 'loans.disburse', 'payments.verify');
        $this->ensureVisible($loan);

        return $this->loanMessage('Loan Closed successfully', $this->workflow->close($loan, $this->currentEmployee()));
    }

    /**
     * Live wright_off_loan (Documents: "Mikopo isiyolipika → Write-Off"), maker/checker (rule 6): the request is PENDING — nothing
     * posted, the loan status unchanged — until a different user with loans.write_off approves it.
     */
    public function writeOff(Request $request, Loan $loan, LoanService $loans): JsonResponse
    {
        $this->authorizeAny('loans.write_off');
        $this->ensureVisible($loan);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        if (! in_array($loan->status, LoanStatus::repayable(), true)) {
            return $this->message('Only active, overdue or default loans can be written off', 422);
        }

        $writeOffRequest = $loans->requestWriteOff($loan, $this->currentEmployee(), $validated['reason'] ?? null);

        return $this->loanMessage('Write-off request submitted; another authorised user must approve it before the loan is written off.', $loan->fresh(), 201, [
            'write_off_request' => ['id' => $writeOffRequest->id, 'status' => $writeOffRequest->status, 'outstanding' => $loans->outstanding($loan->fresh())],
        ]);
    }

    /**
     * GET /loans/write-off-requests?status=pending|approved|rejected|all — write-off requests in scope, with approval flags.
     */
    public function writeOffRequests(Request $request, LoanService $loans, SegregationOfDuties $duties): JsonResponse
    {
        $this->authorizeAny('loans.write_off');
        $status = $request->string('status', WriteOffRequest::PENDING)->toString();
        $viewer = $this->currentEmployee();

        $rows = $this->scoped(WriteOffRequest::query())
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->with(['loan.customer', 'branch', 'requester', 'approver', 'rejecter', 'writeOff'])
            ->latest('id')
            ->get();

        return response()->json(['data' => $rows->map(fn (WriteOffRequest $row): array => [
            'id' => $row->id,
            'status' => $row->status,
            'loan_id' => $row->loan_id,
            'loan_number' => $row->loan?->loan_number,
            'customer' => $row->loan?->customer?->full_name,
            'branch' => $row->branch?->name,
            'reason' => $row->reason,
            'outstanding' => $row->status === WriteOffRequest::PENDING && $row->loan !== null ? $loans->outstanding($row->loan) : null,
            'written_off_amount' => $row->writeOff !== null ? (float) $row->writeOff->amount : null,
            'requested_by' => $row->requester?->full_name,
            'requested_at' => $row->created_at?->toDateTimeString(),
            'approved_by' => $row->approver?->full_name,
            'approved_at' => $row->approved_at?->toDateTimeString(),
            'rejected_by' => $row->rejecter?->full_name,
            'rejected_at' => $row->rejected_at?->toDateTimeString(),
            'rejection_reason' => $row->rejection_reason,
            ...$duties->flags($row->requested_by, $viewer, $row->status === WriteOffRequest::PENDING, true, workflow: ApprovalPolicy::WRITE_OFFS),
        ])->values()]);
    }

    /**
     * POST /loans/write-off-requests/{writeOffRequest}/approve — post the write-off (another authorised user).
     */
    public function approveWriteOff(WriteOffRequest $writeOffRequest, LoanService $loans): JsonResponse
    {
        $this->authorizeAny('loans.write_off');
        $loan = Loan::findOrFail($writeOffRequest->loan_id);
        $this->ensureVisible($loan);

        $writeOff = $loans->approveWriteOff($writeOffRequest, $this->currentEmployee());

        return $this->loanMessage('Loan moved to Write-off successfully', $loan->fresh(), extra: ['write_off' => [
            'amount' => (float) $writeOff->amount,
            'principal_amount' => (float) $writeOff->principal_amount,
            'penalty_amount' => (float) $writeOff->penalty_amount,
            'interest_amount' => (float) $writeOff->interest_amount,
            'insurance_amount' => (float) $writeOff->insurance_amount,
            'written_off_on' => $writeOff->written_off_on?->toDateString(),
        ]]);
    }

    /**
     * POST /loans/write-off-requests/{writeOffRequest}/reject — nothing is posted.
     */
    public function rejectWriteOff(LoanReasonRequest $request, WriteOffRequest $writeOffRequest, LoanService $loans): JsonResponse
    {
        $this->authorizeAny('loans.write_off');
        $loan = Loan::findOrFail($writeOffRequest->loan_id);
        $this->ensureVisible($loan);

        $loans->rejectWriteOff($writeOffRequest, $request->string('reason')->toString(), $this->currentEmployee());

        return $this->loanMessage('Write-off request rejected', $loan->fresh());
    }

    /**
     * POST /loans/{loan}/transactions/{loanTransaction}/reverse — REQUEST the reversal of a repayment (maker/checker). Nothing
     * is posted until another Finance user, an Admin or the Super Admin approves it under Reversal Requests; the approval runs
     * LoanService::reverseRepayment() and the money then returns to suspense unallocated.
     */
    public function reverseRepayment(ReverseRepaymentRequest $request, Loan $loan, LoanTransaction $loanTransaction, ReversalRequests $reversals): JsonResponse
    {
        $this->ensureVisible($loan);
        abort_unless((int) $loanTransaction->loan_id === (int) $loan->id, 404);

        $reversal = $reversals->requestRepayment($loanTransaction, $request->string('reason')->toString(), $this->currentEmployee());

        return $this->loanMessage(self::REVERSAL_REQUESTED, $loan->fresh(), 201, ['reversal_request' => ['id' => $reversal->id, 'status' => $reversal->status]]);
    }

    /**
     * POST /loans/{loan}/reverse-disbursement — REQUEST the reversal of a disbursement (maker/checker, see reverseRepayment()).
     */
    public function reverseDisbursement(ReverseDisbursementRequest $request, Loan $loan, ReversalRequests $reversals): JsonResponse
    {
        $this->ensureVisible($loan);

        $reversal = $reversals->requestDisbursement($loan, $request->string('reason')->toString(), $this->currentEmployee());

        return $this->loanMessage(self::REVERSAL_REQUESTED, $loan->fresh(), 201, ['reversal_request' => ['id' => $reversal->id, 'status' => $reversal->status]]);
    }

    /**
     * Zone manager / any viewer comment on the loan timeline (handwritten note: zone "mtu wa kuview na comment").
     */
    public function comment(Request $request, Loan $loan): JsonResponse
    {
        $this->authorizeAny('loans.view');
        $this->ensureVisible($loan);
        $validated = $request->validate(['comment' => ['required', 'string', 'max:1000']]);

        $this->workflow->comment($loan, $validated['comment'], $this->currentEmployee());

        return $this->message('Comment added successfully');
    }

    /**
     * GET /loans/{id}/agreement: the agreement the system generates after branch manager approval, for printing.
     */
    public function agreement(Loan $loan, LoanAgreement $agreement): JsonResponse
    {
        $this->authorizeAny('loans.view');
        $this->ensureVisible($loan);

        return response()->json(['data' => $agreement->for($loan)]);
    }

    /**
     * Live upload_loan_agrement (PDF): the agreement filled and signed by the customer, after manager approval.
     */
    public function uploadAgreement(Request $request, Loan $loan): JsonResponse
    {
        $this->authorizeAny('loans.apply', 'loans.approve_manager', 'loans.disburse');
        $this->ensureVisible($loan);
        $request->validate(['attach' => ['required', 'file', 'mimes:pdf', 'max:10240']], ['attach.mimes' => 'PDF file is Allowed please change Your file']);

        $loan = $this->workflow->uploadAgreement($loan, $request->file('attach'), $this->currentEmployee());

        return $this->loanMessage('Loan Agreement uploaded successfully', $loan);
    }

    /**
     * Documents: "Cron Job POST /loans/overdue/process" — also runs daily via loans:process-overdue.
     */
    public function processOverdue(LoanService $loans): JsonResponse
    {
        $this->authorizeAny('penalties.manage');

        $summary = $loans->applyPenaltiesAndDefaults(CarbonImmutable::today());

        return $this->message('Overdue loans processed successfully', 200, ['data' => $summary]);
    }

    private function authorizeStage(Loan $loan): void
    {
        $this->ensureVisible($loan);

        if ($loan->status === LoanStatus::PendingCreditReview) {
            $this->authorizeAny('loans.credit_review');
        } else {
            $this->authorizeAny('loans.approve_manager');
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function loanMessage(string $message, Loan $loan, int $status = 200, array $extra = []): JsonResponse
    {
        return $this->message($message, $status, ['data' => new LoanResource($loan->loadMissing(['customer', 'branch', 'category']))] + $extra);
    }
}

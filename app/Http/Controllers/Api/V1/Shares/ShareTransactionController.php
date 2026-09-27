<?php

namespace App\Http\Controllers\Api\V1\Shares;

use App\Enums\ShareTransactionType;
use App\Http\Requests\Api\Shares\ShareAdjustmentRequest;
use App\Http\Requests\Api\Shares\ShareCancellationRequest;
use App\Http\Requests\Api\Shares\ShareIssuanceRequest;
use App\Http\Requests\Api\Shares\ShareReversalRequest;
use App\Http\Requests\Api\Shares\ShareTransferRequest;
use App\Http\Resources\Api\V1\Shares\ShareTransactionResource;
use App\Models\ApprovalPolicy;
use App\Models\ShareHolder;
use App\Models\ShareIssuanceRequest as PendingShareIssuance;
use App\Models\ShareTransaction;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Reports\ShareReports;
use App\Services\Shares\ShareIssuance;
use App\Services\Shares\ShareRegister;
use App\Services\Shares\ShareTransfers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shares → Share Transactions, Issue Shares, Transfer Shares, cancellations, adjustments and reversals.
 *
 * C6 maker/checker: a PAID issuance is recorded as a pending request (202, nothing posted) and posted when a different
 * authorised user approves it (POST issuance-requests/{id}/approve) or rejected (POST issuance-requests/{id}/reject).
 * Linked-contribution and bonus issuances post no journal and are recorded at once.
 */
class ShareTransactionController extends SharesController
{
    public function __construct(
        private readonly ShareRegister $register,
        private readonly ShareIssuance $issuance,
        private readonly ShareTransfers $transfers,
        private readonly ShareReports $reports,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('shares.view');
        $request->validate([
            'type' => ['nullable', Rule::enum(ShareTransactionType::class)],
            'share_holder_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:completed,reversed'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $rows = $this->reports->transactions(
            $this->companyId(),
            $request->filled('type') ? [ShareTransactionType::from($request->string('type')->toString())] : [],
            $request->filled('share_holder_id') ? $request->integer('share_holder_id') : null,
            $request->input('status'),
            $this->date($request, 'from'),
            $this->date($request, 'to'),
        );

        return response()->json(['data' => ShareTransactionResource::collection($rows)]);
    }

    public function show(ShareTransaction $shareTransaction): JsonResponse
    {
        $this->authorizeAny('shares.view');
        $this->ensureCompany($shareTransaction);

        return response()->json(['data' => new ShareTransactionResource($shareTransaction->load(ShareReports::TRANSACTION_RELATIONS))]);
    }

    /**
     * Supporting document from private storage.
     */
    public function document(ShareTransaction $shareTransaction): StreamedResponse
    {
        $this->authorizeAny('shares.view');
        $this->ensureCompany($shareTransaction);

        abort_unless($shareTransaction->document_path && Storage::disk(ShareTransaction::DISK)->exists($shareTransaction->document_path), 404);

        return Storage::disk(ShareTransaction::DISK)->response($shareTransaction->document_path, $shareTransaction->document_name, ['Cache-Control' => 'private, max-age=300'], 'inline');
    }

    public function issue(ShareIssuanceRequest $request): JsonResponse
    {
        if ($request->input('type') === ShareTransactionType::Issuance->value && $request->input('payment_treatment') === ShareTransaction::TREATMENT_PAID) {
            $pending = $this->issuance->requestIssuance(
                $this->holder($request->integer('share_holder_id')),
                $request->integer('shares'),
                $request->filled('price_per_share') ? $request->float('price_per_share') : null,
                $this->date($request, 'issue_date'),
                [
                    'pay_method' => $request->input('pay_method'),
                    'bank_account_id' => $request->filled('bank_account_id') ? $request->integer('bank_account_id') : null,
                    'receipt_number' => $request->input('receipt_number'),
                    'cheque_number' => $request->input('cheque_number'),
                ],
                $request->input('notes'),
                $this->currentEmployee(),
                $request->input('idempotency_key'),
                $request->file('document'),
            );

            return $this->message(
                $pending['created'] ? 'Share Issuance Requested successfully — awaiting approval by another authorised user' : 'The share issuance was already requested',
                $pending['created'] ? 202 : 200,
                ['data' => $this->presentIssuanceRequest($pending['request']->load(['shareHolder', 'requester', 'bankAccount']))],
            );
        }

        $result = $this->issuance->issue(
            $this->holder($request->integer('share_holder_id')),
            $request->integer('shares'),
            $request->string('type')->toString(),
            (string) $request->input('payment_treatment', ''),
            $request->filled('price_per_share') ? $request->float('price_per_share') : null,
            $this->date($request, 'issue_date'),
            [
                'pay_method' => $request->input('pay_method'),
                'bank_account_id' => $request->filled('bank_account_id') ? $request->integer('bank_account_id') : null,
                'receipt_number' => $request->input('receipt_number'),
                'cheque_number' => $request->input('cheque_number'),
                'capital_id' => $request->filled('capital_id') ? $request->integer('capital_id') : null,
            ],
            $request->input('notes'),
            $this->currentEmployee(),
            $request->input('idempotency_key'),
            $request->file('document'),
        );

        return $this->respond($result, 'Shares Issued successfully', 'Shares were already issued');
    }

    /**
     * Paid share issuance requests (status=pending by default | approved | rejected | all).
     */
    public function issuanceRequests(Request $request): JsonResponse
    {
        $this->authorizeAny('shares.view', 'shares.issue');
        $validated = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'all'])]]);
        $status = $validated['status'] ?? 'pending';

        $rows = PendingShareIssuance::where('company_id', $this->companyId())
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->with(['shareHolder', 'requester', 'approver', 'rejecter', 'bankAccount', 'shareTransaction'])
            ->latest('id')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (PendingShareIssuance $row): array => $this->presentIssuanceRequest($row))->values(),
            'pending_total' => round((float) PendingShareIssuance::where('company_id', $this->companyId())->where('status', PendingShareIssuance::STATUS_PENDING)->sum('total_amount'), 2),
        ]);
    }

    public function approveIssuanceRequest(PendingShareIssuance $issuanceRequest): JsonResponse
    {
        $this->authorizeAny('shares.issue');
        $this->ensureCompany($issuanceRequest);

        $approved = $this->issuance->approveIssuance($issuanceRequest, $this->currentEmployee());

        return $this->message('Share Issuance Approved successfully', 200, ['data' => $this->presentIssuanceRequest($approved->fresh(['shareHolder', 'requester', 'approver', 'bankAccount', 'shareTransaction']))]);
    }

    public function rejectIssuanceRequest(Request $request, PendingShareIssuance $issuanceRequest): JsonResponse
    {
        $this->authorizeAny('shares.issue');
        $this->ensureCompany($issuanceRequest);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $rejected = $this->issuance->rejectIssuance($issuanceRequest, $validated['reason'], $this->currentEmployee());

        return $this->message('Share Issuance Rejected successfully', 200, ['data' => $this->presentIssuanceRequest($rejected->fresh(['shareHolder', 'requester', 'rejecter', 'bankAccount']))]);
    }

    public function transfer(ShareTransferRequest $request): JsonResponse
    {
        $result = $this->transfers->transfer(
            $this->holder($request->integer('from_share_holder_id')),
            $this->holder($request->integer('to_share_holder_id')),
            $request->integer('shares'),
            $request->filled('consideration_per_share') ? $request->float('consideration_per_share') : null,
            $this->date($request, 'transfer_date'),
            $request->input('notes'),
            $this->currentEmployee(),
            $request->input('idempotency_key'),
            $request->file('document'),
        );

        return $this->respond($result, 'Shares Transferred successfully', 'Shares were already transferred');
    }

    public function cancel(ShareCancellationRequest $request): JsonResponse
    {
        $result = $this->register->cancel(
            $this->holder($request->integer('share_holder_id')),
            $request->integer('shares'),
            $this->date($request, 'transaction_date'),
            $request->string('reason')->toString(),
            $this->currentEmployee(),
            $request->input('idempotency_key'),
            $request->file('document'),
        );

        return $this->respond($result, 'Shares Cancelled successfully', 'Shares were already cancelled');
    }

    public function adjust(ShareAdjustmentRequest $request): JsonResponse
    {
        $result = $this->register->adjust(
            $this->holder($request->integer('share_holder_id')),
            $request->string('direction')->toString(),
            $request->integer('shares'),
            $request->string('reason')->toString(),
            $this->currentEmployee(),
            $request->input('idempotency_key'),
        );

        return $this->respond($result, 'Shares Adjusted successfully', 'The adjustment was already recorded');
    }

    public function reverse(ShareReversalRequest $request, ShareTransaction $shareTransaction): JsonResponse
    {
        $this->ensureCompany($shareTransaction);

        $result = $this->register->reverse($shareTransaction, $request->string('reason')->toString(), $this->currentEmployee());

        return $this->respond($result, 'Share Transaction Reversed successfully', 'Share transaction was already reversed');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentIssuanceRequest(PendingShareIssuance $row): array
    {
        $pending = $row->isPending();
        $mayApprove = Gate::allows('shares.issue');

        return [
            'id' => $row->id,
            'share_holder_id' => $row->share_holder_id,
            'share_holder' => $row->shareHolder?->full_name,
            'shares' => (int) $row->shares,
            'price_per_share' => (float) $row->price_per_share,
            'amount' => (float) $row->total_amount,
            'issue_date' => $row->issue_date?->toDateString(),
            'pay_method' => $row->pay_method,
            'bank_account' => $row->bankAccount?->name,
            'receipt_number' => $row->receipt_number,
            'cheque_number' => $row->cheque_number,
            'notes' => $row->notes,
            'document_name' => $row->document_name,
            'status' => $row->status,
            'requested_by' => $row->requester?->full_name,
            'requested_at' => $row->created_at?->toDateTimeString(),
            'approved_by' => $row->approver?->full_name,
            'approved_at' => $row->approved_at?->toDateTimeString(),
            'rejected_by' => $row->rejecter?->full_name,
            'rejected_at' => $row->rejected_at?->toDateTimeString(),
            'rejection_reason' => $row->rejection_reason,
            'share_transaction_reference' => $row->shareTransaction?->reference,
            ...app(SegregationOfDuties::class)->flags($this->issuance->requestInitiatorIds($row), $this->currentEmployee(), $pending, $mayApprove, workflow: ApprovalPolicy::SHARE_ISSUANCES),
            'can_reject' => $pending && $mayApprove,
        ];
    }

    private function holder(int $id): ShareHolder
    {
        return ShareHolder::where('company_id', $this->companyId())->findOrFail($id);
    }

    /**
     * @param  array{transaction: ShareTransaction, created: bool}  $result
     */
    private function respond(array $result, string $created, string $replayed): JsonResponse
    {
        return $this->message(
            $result['created'] ? $created : $replayed,
            $result['created'] ? 201 : 200,
            ['data' => new ShareTransactionResource($result['transaction']->load(ShareReports::TRANSACTION_RELATIONS))],
        );
    }
}

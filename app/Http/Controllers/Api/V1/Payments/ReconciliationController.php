<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Payments\ReasonRequest;
use App\Http\Requests\Api\Payments\VerifyDepositRequest;
use App\Http\Resources\Api\V1\Payments\TellerDepositResource;
use App\Models\ApprovalPolicy;
use App\Models\TellerDeposit;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Payments → Bank Reconciliation: teller deposit slips vs teller cash vs bank statement.
 * Verify (match statement) → Confirm (ledger + loan allocation + SMS), or Reject with reason.
 */
class ReconciliationController extends ApiController
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAny('payments.verify');

        $query = $this->scoped(TellerDeposit::query())
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('zone_id') && $request->input('zone_id') !== 'all', fn ($query) => $query->whereHas('branch', fn ($branch) => $branch->where('zone_id', $request->integer('zone_id'))))
            ->with(['branch', 'employee', 'bankAccount', 'verifier', 'confirmer', 'payments.customer', 'payments.loan'])
            ->latest('deposit_date')
            ->latest('id');
        $this->applyFilters($query, $request, 'deposit_date');

        return TellerDepositResource::collection($query->get());
    }

    /**
     * Finance checks the slip against the bank statement. A matching slip is posted at once — the money reaches the loans and
     * the bank — because the teller made the deposit and Finance is the checker: there is no separate Confirm click to forget.
     * A mismatch stays unposted for investigation. Rule 6: the teller who recorded the deposit cannot verify it.
     */
    public function verify(VerifyDepositRequest $request, TellerDeposit $tellerDeposit, SegregationOfDuties $duties): JsonResponse
    {
        $this->authorizeAny('payments.verify');
        $this->assertBranchAccessible((int) $tellerDeposit->branch_id);
        $duties->assertCanApprove($tellerDeposit->employee_id, $this->currentEmployee(), 'teller deposit', workflow: ApprovalPolicy::TELLER_DEPOSITS);

        $deposit = DB::transaction(function () use ($request, $tellerDeposit): TellerDeposit {
            $deposit = $this->payments->verifyDeposit($tellerDeposit, (float) $request->input('statement_amount'), $request->string('statement_reference')->toString(), $this->currentEmployee());

            return $deposit->status === TellerDeposit::STATUS_VERIFIED ? $this->payments->confirmDeposit($deposit, $this->currentEmployee()) : $deposit;
        });

        return $deposit->status === TellerDeposit::STATUS_CONFIRMED
            ? $this->message('Deposit verified and posted: the loans are reduced and the money is in the bank')
            : $this->message('Amount mismatch: deposit kept pending for investigation', 200, ['mismatch' => true]);
    }

    /**
     * Posts a slip verified before verification started posting by itself (older deposits left at "verified").
     * Rule 6: the teller who recorded the deposit cannot confirm (post) it.
     */
    public function confirm(TellerDeposit $tellerDeposit, SegregationOfDuties $duties): JsonResponse
    {
        $this->authorizeAny('payments.verify');
        $this->assertBranchAccessible((int) $tellerDeposit->branch_id);
        $duties->assertCanApprove($tellerDeposit->employee_id, $this->currentEmployee(), 'teller deposit', workflow: ApprovalPolicy::TELLER_DEPOSITS);

        $this->payments->confirmDeposit($tellerDeposit, $this->currentEmployee());

        return $this->message('Payment confirmed successfully');
    }

    /**
     * Rule 6: the teller who recorded the deposit cannot reject it.
     */
    public function reject(ReasonRequest $request, TellerDeposit $tellerDeposit, SegregationOfDuties $duties): JsonResponse
    {
        $this->authorizeAny('payments.verify');
        $this->assertBranchAccessible((int) $tellerDeposit->branch_id);
        $duties->assertCanApprove($tellerDeposit->employee_id, $this->currentEmployee(), 'teller deposit', workflow: ApprovalPolicy::TELLER_DEPOSITS);

        $this->payments->rejectDeposit($tellerDeposit, $request->string('reason')->toString(), $this->currentEmployee());

        return $this->message('Deposit rejected successfully');
    }
}

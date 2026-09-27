<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Payments\ReasonRequest;
use App\Http\Resources\Api\V1\Payments\PaymentResource;
use App\Models\ApprovalPolicy;
use App\Models\Payment;
use App\Models\Zone;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payments → Cash Verification: Finance view of every teller cash receipt, filtered by branch / zone / date
 * (handwritten note "Filter by branch / Date / Zone"). Wrong receipts are rejected with a reason (ledger reversal).
 */
class CashVerificationController extends ApiController
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('payments.verify');

        $query = $this->scoped(Payment::query())
            ->where('source', Payment::SOURCE_TELLER)
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('zone_id') && $request->input('zone_id') !== 'all', fn ($query) => $query->whereHas('branch', fn ($branch) => $branch->where('zone_id', $request->integer('zone_id'))))
            ->with(['customer', 'branch', 'employee', 'loan', 'tellerDeposit', 'verifier'])
            ->latest('paid_on')
            ->latest('id');
        $this->applyFilters($query, $request, 'paid_on');

        $rows = $query->get();

        return response()->json([
            'data' => PaymentResource::collection($rows),
            'totals' => collect(PaymentStatus::cases())
                ->mapWithKeys(fn (PaymentStatus $status): array => [$status->value => round((float) $rows->where('status', $status)->sum('amount'), 2)])
                ->filter()
                ->all(),
        ]);
    }

    /**
     * Rule 6: rejecting a teller cash receipt reverses its posting, so the teller who recorded it cannot reject it.
     */
    public function reject(ReasonRequest $request, Payment $payment, SegregationOfDuties $duties): JsonResponse
    {
        $this->authorizeAny('payments.verify');
        if ($payment->branch_id !== null) {
            $this->assertBranchAccessible((int) $payment->branch_id);
        }
        $duties->assertCanApprove($payment->employee_id, $this->currentEmployee(), 'cash receipt', SegregationOfDuties::REVERSER_MESSAGE, workflow: ApprovalPolicy::TELLER_DEPOSITS);

        $this->payments->rejectCash($payment, $request->string('reason')->toString(), $this->currentEmployee());

        return $this->message('Cash payment rejected successfully');
    }

    /**
     * Zones as {value,label} for the branch / date / zone filter (handwritten note "Filter by branch / Date / Zone").
     */
    public function zoneOptions(): JsonResponse
    {
        $this->authorizeAny('payments.verify', 'payments.suspense');

        return response()->json(['data' => Zone::where('company_id', $this->currentEmployee()->company_id)->orderBy('name')->get()
            ->map(fn (Zone $zone): array => ['value' => (string) $zone->id, 'label' => $zone->name])
            ->push(['value' => 'all', 'label' => 'ALL'])
            ->values()]);
    }
}

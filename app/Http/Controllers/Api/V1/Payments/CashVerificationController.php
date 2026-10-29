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
    /**
     * @var list<string>
     */
    private const UNRESOLVED = ['pending_verification', 'deposited'];

    /** The day Finance decided; for a receipt decided before verified_at was kept, the day it was paid. */
    private const DECISION_DATE = 'COALESCE(payments.verified_at, payments.paid_on)';

    public function __construct(private readonly PaymentService $payments) {}

    /**
     * Enhancement §8.1 tabs: Pending and Deposited hold every unresolved receipt whatever its date (they never drop off when
     * the day changes); Confirmed and Rejected follow the date filter on the decision date. `summary` gives every tab's
     * count and total under the same rules and the same branch filter.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('payments.verify');

        $base = function () use ($request) {
            return $this->scoped(Payment::query())
                ->where('source', Payment::SOURCE_TELLER)
                ->when($request->filled('zone_id') && $request->input('zone_id') !== 'all', fn ($query) => $query->whereHas('branch', fn ($branch) => $branch->where('zone_id', $request->integer('zone_id'))));
        };
        $forTab = function (?string $status) use ($base, $request) {
            $query = $base()->when($status !== null && $status !== 'all', fn ($query) => $query->where('status', $status));
            $this->applyFilters($query, $request);
            if (! in_array($status, self::UNRESOLVED, true)) {
                $query->when($request->filled('from'), fn ($query) => $query->whereRaw('DATE('.self::DECISION_DATE.') >= ?', [$request->date('from')->toDateString()]))
                    ->when($request->filled('to'), fn ($query) => $query->whereRaw('DATE('.self::DECISION_DATE.') <= ?', [$request->date('to')->toDateString()]));
            }

            return $query;
        };

        $status = $request->filled('status') ? $request->string('status')->toString() : null;
        $rows = $forTab($status)
            ->with(['customer', 'branch', 'employee', 'loan', 'tellerDeposit', 'verifier'])
            ->latest('paid_on')
            ->latest('id')
            ->get();

        $summary = collect([PaymentStatus::PendingVerification, PaymentStatus::Deposited, PaymentStatus::Confirmed, PaymentStatus::Rejected])
            ->mapWithKeys(function (PaymentStatus $tab) use ($forTab): array {
                $totals = $forTab($tab->value)->selectRaw('COUNT(*) as records, COALESCE(SUM(amount), 0) as amount')->first();

                return [$tab->value => ['count' => (int) $totals->records, 'amount' => round((float) $totals->amount, 2)]];
            })->all();

        return response()->json([
            'data' => PaymentResource::collection($rows),
            'summary' => $summary,
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

<?php

namespace App\Http\Controllers\Api\V1\Loans;

use App\Models\LoanMandate;
use App\Services\AccessControl;
use App\Services\MandateCollections;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * E-Mandate management (enhancement §4): mandate list, reports, bank collection files and confirmed cancellations.
 */
class LoanMandateController extends LoanApiController
{
    public function __construct(private MandateCollections $collections) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('loans.view');
        $branchIds = app(AccessControl::class)->branchIds($this->currentEmployee());

        $mandates = LoanMandate::query()->where('loan_mandates.company_id', $this->currentEmployee()->company_id)
            ->with(['loan:id,loan_number,reference_number,branch_id,customer_id,status', 'loan.customer:id,first_name,middle_name,last_name', 'loan.branch:id,name'])
            ->whereHas('loan', fn ($query) => $query
                ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds))
                ->when($request->filled('branch_id') && $request->input('branch_id') !== 'all', fn ($query) => $query->where('branch_id', $request->integer('branch_id'))))
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->latest('id')->limit(1000)->get();

        return response()->json(['data' => $mandates->map(fn (LoanMandate $mandate): array => [
            'id' => $mandate->id,
            'loan_id' => $mandate->loan_id,
            'loan_number' => $mandate->loan?->loan_number,
            'customer' => $mandate->loan?->customer?->full_name,
            'branch' => $mandate->loan?->branch?->name,
            'bank_name' => $mandate->bank_name,
            'provider' => $mandate->provider,
            'channel' => $mandate->channel,
            'mandate_type' => $mandate->mandate_type,
            'mandate_reference' => $mandate->mandate_reference,
            'status' => $mandate->status,
            'cancellation_status' => $mandate->cancellation_status,
            'cancellation_reference' => $mandate->cancellation_reference,
            'activated_at' => $mandate->activated_at?->toDateTimeString(),
            'cancelled_at' => $mandate->cancelled_at?->toDateTimeString(),
        ])->all()]);
    }

    public function report(Request $request): JsonResponse
    {
        $this->authorizeAny('loans.view');
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $branchIds = app(AccessControl::class)->branchIds($this->currentEmployee());
        if ($request->filled('branch_id') && $request->input('branch_id') !== 'all') {
            $this->assertBranchAccessible($request->integer('branch_id'));
            $branchIds = [$request->integer('branch_id')];
        }
        $from = $request->filled('from') ? CarbonImmutable::parse($request->string('from')->toString()) : CarbonImmutable::today()->startOfMonth();
        $to = $request->filled('to') ? CarbonImmutable::parse($request->string('to')->toString()) : CarbonImmutable::today()->endOfMonth();

        return response()->json(['data' => $this->collections->report($this->currentEmployee()->company_id, $branchIds, $from, $to)
            + ['filter' => ['branch_id' => $request->input('branch_id', 'all'), 'from' => $from->toDateString(), 'to' => $to->toDateString()]]]);
    }

    /**
     * POST loans/mandates/collections — Finance uploads the bank's collection file (CSV with mandate_reference,
     * payment_reference, amount, status, and optionally collection_date and remarks). Repeated references are ignored.
     */
    public function import(Request $request): JsonResponse
    {
        $this->authorizeAny('payments.verify');
        $validated = $request->validate([
            'provider' => ['required', 'string', 'max:60'],
            'channel' => ['nullable', 'string', 'max:40'],
            'collection_date' => ['required', 'date'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $result = $this->collections->importFile($this->currentEmployee()->company_id, $validated['provider'], $validated['channel'] ?? null, $validated['collection_date'], $request->file('file')->getRealPath(), $this->currentEmployee());

        return response()->json([
            'message' => "{$result['recorded']} collection(s) recorded, {$result['repeated']} already recorded before.",
            'data' => ['batch_id' => $result['batch']->id, 'reference' => $result['batch']->reference, 'recorded' => $result['recorded'], 'repeated' => $result['repeated']],
        ], 201);
    }

    /**
     * POST loans/mandates/{mandate}/confirm-cancellation — Finance records the bank's confirmation reference.
     */
    public function confirmCancellation(Request $request, LoanMandate $mandate): JsonResponse
    {
        $this->authorizeAny('payments.verify', 'loans.disburse');
        $this->ensureVisible($mandate->loan);
        $validated = $request->validate(['reference' => ['required', 'string', 'max:100']]);

        $mandate = $this->collections->confirmCancellation($mandate, $validated['reference']);

        return response()->json(['message' => 'Mandate cancellation confirmed', 'data' => ['id' => $mandate->id, 'cancellation_status' => $mandate->cancellation_status, 'cancelled_at' => $mandate->cancelled_at?->toDateTimeString()]]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Hrm;

use App\Models\CommissionAllocation;
use App\Services\AccessControl;
use App\Services\Hrm\CommissionEngine;
use App\Services\Hrm\CommissionPayments;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * HRM → Commission: branch commission pools, distribution per staff and zone manager override
 * (OVERVIEW ALL REPORT "Commission Report", "Zone Manager Commission Report", "Commission Eligibility"), and the commission
 * payment flow of spec §21 / §22 ({@see CommissionPayments}): HR (payroll.approve) finalises and requests payment, Finance
 * (payroll.pay) approves or rejects and pays. Staff see their own commission through `mine`.
 */
class CommissionController extends HrmController
{
    public function __construct(
        private readonly CommissionEngine $commission,
        private readonly CommissionPayments $payments,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorizeAny('payroll.approve', 'reports.financial');

        $report = $this->commission->report($this->companyId(), $this->month($request));
        $branchIds = app(AccessControl::class)->branchIds($this->currentEmployee());

        if ($branchIds !== null) {
            $report['branches'] = array_values(array_filter($report['branches'], fn (array $branch): bool => in_array((int) $branch['branch_id'], $branchIds, true)));
            $report['zone_managers'] = [];
            $report['summary'] = $this->commission->summary($report['branches']);
        }

        return response()->json(['data' => $report + ['month' => $this->month($request)->format('Y-m')]]);
    }

    public function calculate(Request $request): JsonResponse
    {
        $this->authorizeAny('payroll.approve');
        $request->validate(['period' => ['required', 'date_format:Y-m']]);

        $rows = $this->commission->calculate($this->companyId(), $this->month($request), $this->currentEmployee());

        return $this->message('Commission Calculated successfully', 200, ['data' => ['total' => round((float) $rows->sum('amount'), 2)]]);
    }

    /**
     * Commission payments of a closed month per employee: figures, status and the audit trail of the payment flow.
     */
    public function payments(Request $request): JsonResponse
    {
        $this->authorizeAny('payroll.approve', 'payroll.pay');
        $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'status' => ['nullable', Rule::in(array_keys(CommissionAllocation::STATUS_LABELS))],
            'employee_id' => ['nullable', 'integer'],
        ]);

        $allocations = $this->payments->select($this->companyId(), $this->month($request), null, app(AccessControl::class)->branchIds($this->currentEmployee()), $request->filled('employee_id') ? $request->integer('employee_id') : null)
            ->when($request->filled('status'), fn (Collection $rows) => $rows->where('payment_status', $request->string('status')->toString()));

        $rows = $this->present($allocations, Gate::allows('payroll.pay'));

        return response()->json(['data' => [
            'period' => $this->month($request)->format('Y-m'),
            'period_closed' => $this->commission->closedPeriod($this->companyId(), $this->month($request)) !== null,
            'summary' => $this->payments->summary($rows),
            'rows' => $rows,
        ]]);
    }

    /**
     * Staff portal: the signed-in employee's own commission per period and its payment status (spec §21).
     */
    /** @var list<string> */
    private const STAFF_HIDDEN = [
        'can_approve', 'approve_blocked_reason', 'can_pay', 'pay_blocked_reason',
        'distributable_profit', 'pool_amount', 'commission_base', 'offset_amount', 'paying_account', 'journal_entry_id',
    ];

    public function mine(): JsonResponse
    {
        $employee = $this->currentEmployee();
        $allocations = $this->payments->select($this->companyId(), null, null, null, (int) $employee->id)->sortByDesc('accounting_period_id');

        return response()->json(['data' => collect($this->present($allocations, false))
            // §60: staff see their own commission, never the branch figures behind it (profit, pool, base, offset) or how it
            // was paid out of the company's accounts.
            ->map(fn (array $row): array => collect($row)->except(self::STAFF_HIDDEN)->all())
            ->values()->all()]);
    }

    public function finalize(Request $request): JsonResponse
    {
        $this->authorizeAny('payroll.approve');
        $count = $this->payments->finalize($this->selection($request, [CommissionAllocation::STATUS_CALCULATED]), $this->currentEmployee());

        return $this->message("Commission Finalised successfully ({$count})", 200, ['data' => ['count' => $count]]);
    }

    public function requestPayment(Request $request): JsonResponse
    {
        $this->authorizeAny('payroll.approve');
        $count = $this->payments->request($this->selection($request, [CommissionAllocation::STATUS_CALCULATED, CommissionAllocation::STATUS_AWAITING_REQUEST]), $this->currentEmployee());

        return $this->message("Commission Payment Requested successfully ({$count})", 200, ['data' => ['count' => $count]]);
    }

    public function approve(Request $request): JsonResponse
    {
        $this->authorizeAny('payroll.pay');
        $count = $this->payments->approve($this->selection($request, [CommissionAllocation::STATUS_REQUESTED]), $this->currentEmployee());

        return $this->message("Commission Payment Approved successfully ({$count})", 200, ['data' => ['count' => $count]]);
    }

    public function reject(Request $request): JsonResponse
    {
        $this->authorizeAny('payroll.pay');
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $count = $this->payments->reject($this->selection($request, [CommissionAllocation::STATUS_REQUESTED]), $this->currentEmployee(), $validated['reason']);

        return $this->message("Commission Payment Rejected successfully ({$count})", 200, ['data' => ['count' => $count]]);
    }

    public function pay(Request $request): JsonResponse
    {
        $this->authorizeAny('payroll.pay');
        $validated = $request->validate([
            'ac_id' => ['required', Rule::in([CommissionPayments::PAYING_INTEREST, CommissionPayments::PAYING_COMPANY])],
            'paid_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $paidOn = isset($validated['paid_on']) ? CarbonImmutable::parse($validated['paid_on']) : CarbonImmutable::today();

        $count = $this->payments->pay($this->selection($request, [CommissionAllocation::STATUS_FINANCE_APPROVED]), $this->currentEmployee(), $paidOn, $validated['ac_id']);

        return $this->message("Commission Paid successfully ({$count})", 200, ['data' => ['count' => $count]]);
    }

    /**
     * The allocations an action applies to: explicit `ids`, or every allocation of `period` in the statuses the action starts
     * from (bulk) — always within the viewer's company and branches.
     *
     * @param  list<string>  $bulkStatuses
     * @return Collection<int, CommissionAllocation>
     */
    private function selection(Request $request, array $bulkStatuses): Collection
    {
        $validated = $request->validate([
            'ids' => ['required_without:period', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'period' => ['required_without:ids', 'date_format:Y-m'],
        ]);
        $branchIds = app(AccessControl::class)->branchIds($this->currentEmployee());

        if (isset($validated['ids'])) {
            $rows = $this->payments->select($this->companyId(), null, array_map('intval', $validated['ids']), $branchIds);
            abort_if($rows->count() !== count(array_unique($validated['ids'])), 404);

            return $rows;
        }

        $rows = $this->payments->select($this->companyId(), $this->month($request), null, $branchIds);

        return $rows->whereIn('payment_status', $bulkStatuses)->whereNull('payroll_run_id')->values();
    }

    /**
     * @param  Collection<int, CommissionAllocation>  $allocations
     * @return list<array<string, mixed>>
     */
    private function present(Collection $allocations, bool $canDecide): array
    {
        $outstanding = $this->payments->outstandingNegligence($allocations->pluck('employee_id')->map(fn ($id): int => (int) $id)->all());

        return $allocations->map(fn (CommissionAllocation $row): array => $this->payments->present($row, $outstanding, $this->currentEmployee(), $canDecide))->values()->all();
    }
}

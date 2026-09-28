<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Accounting\CalculatePeriodRequest;
use App\Http\Resources\Api\V1\Accounting\AccountingPeriodResource;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Services\PeriodClose;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Accounting → Month End & Profit (ACCOUNT OVERVIEW "Month end process", STAFF COMMISSION §7).
 * Handwritten notes: "kila mwezi faida au hasara itachekiwa na kuwekwa report; mwezi unaofata unaanza upya".
 */
class PeriodCloseController extends ApiController
{
    public function __construct(private readonly PeriodClose $periodClose) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorizeAny('accounting.close_period', 'reports.financial');

        $periods = AccountingPeriod::where('company_id', $this->currentEmployee()->company_id)
            ->with('closedBy:id,first_name,middle_name,last_name')
            ->orderByDesc('period_start')
            ->get();

        return AccountingPeriodResource::collection($periods);
    }

    public function show(AccountingPeriod $period): AccountingPeriodResource
    {
        $this->authorizeAny('accounting.close_period', 'reports.financial');
        $this->assertOwnCompany($period);

        return new AccountingPeriodResource($this->withVisibleResults($period));
    }

    /**
     * Calculate (or refresh) the profit of a month for every branch.
     */
    public function store(CalculatePeriodRequest $request): JsonResponse
    {
        $this->authorizeAny('accounting.close_period');

        $period = $this->periodClose->calculate($this->currentEmployee()->company_id, CarbonImmutable::parse($request->string('month')->toString().'-01'));

        return $this->message('Profit Calculated successfully', 200, ['data' => new AccountingPeriodResource($this->withVisibleResults($period))]);
    }

    public function close(Request $request, AccountingPeriod $period): JsonResponse
    {
        $this->authorizeAny('accounting.close_period');
        $this->assertOwnCompany($period);

        $closed = $this->periodClose->close($period, $this->currentEmployee());

        AuditLog::create([
            'company_id' => $closed->company_id,
            'employee_id' => $this->currentEmployee()->id,
            'action' => 'AccountingPeriod.closed',
            'auditable_type' => $closed->getMorphClass(),
            'auditable_id' => $closed->id,
            'before' => ['status' => AccountingPeriod::STATUS_OPEN],
            'after' => [
                'status' => AccountingPeriod::STATUS_CLOSED,
                'net_profit' => round((float) $closed->results->sum('net_profit'), 2),
                'hq_hold_amount' => round((float) $closed->results->sum('hq_hold_amount'), 2),
                'distributable_profit' => round((float) $closed->results->sum('distributable_profit'), 2),
            ],
            'ip_address' => $request->ip(),
        ]);

        return $this->message('Period Closed successfully', 200, ['data' => new AccountingPeriodResource($this->withVisibleResults($closed))]);
    }

    private function assertOwnCompany(AccountingPeriod $period): void
    {
        abort_unless((int) $period->company_id === (int) $this->currentEmployee()->company_id, 404);
    }

    private function withVisibleResults(AccountingPeriod $period): AccountingPeriod
    {
        $branchIds = $this->visibleBranches()->modelKeys();

        return $period->load([
            'closedBy:id,first_name,middle_name,last_name',
            'results' => fn ($query) => $query->whereIn('branch_id', $branchIds)->with('branch:id,name')->orderBy('branch_id'),
        ]);
    }
}

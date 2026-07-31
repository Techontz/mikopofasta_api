<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Actions\GenerateCommissionPoolsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\GenerateCommissionRequest;
use App\Http\Resources\CommissionPoolResource;
use App\Http\Resources\ZoneCommissionDistributionResource;
use App\Models\Branch;
use App\Models\CommissionPool;
use App\Models\ZoneCommissionDistribution;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Commission — §15.5.
 *
 * Nothing here posts to the ledger. A pool is an entitlement rather than a
 * transaction: the money is recognised once, as Commission Expense on the
 * recipient's payroll entry (§5). An endpoint that posted a pool would expense
 * the same shillings twice.
 */
final class CommissionController extends Controller
{
    /**
     * GET /api/v1/commission — pools for a period.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CommissionPool::class);

        $period = $request->string('period')->toString();

        $pools = CommissionPool::query()
            ->with(['branch', 'distributions.staffProfile.user'])
            ->when($period !== '', fn ($q) => $q->where('period', $period))
            ->orderBy('branch_id')
            ->get();

        $zones = ZoneCommissionDistribution::query()
            ->with('zone')
            ->when($period !== '', fn ($q) => $q->where('period', $period))
            ->get();

        return ApiResponse::data(CommissionPoolResource::collection($pools), [
            'period' => $period === '' ? null : $period,
            'totalPool' => Money::sum($pools->map(fn (CommissionPool $p): Money => $p->poolAmount()))->toDecimalString(),

            // How many branches earned nothing this period because a loss has
            // not been offset — the number a manager actually asks about.
            'blockedByLoss' => $pools->reject(fn (CommissionPool $p): bool => $p->isDistributable())->count(),

            'zoneOverrides' => ZoneCommissionDistributionResource::collection($zones),
        ]);
    }

    /**
     * GET /api/v1/commission/branches/{branch} — §15.5, "Commission pool +
     * distribution breakdown for a period".
     */
    public function branch(Request $request, Branch $branch): JsonResponse
    {
        $this->authorize('viewAny', CommissionPool::class);

        $period = $request->string('period')->toString();

        $pools = CommissionPool::query()
            ->with(['branch', 'distributions.staffProfile.user'])
            ->where('branch_id', $branch->getKey())
            ->when($period !== '', fn ($q) => $q->where('period', $period))
            ->orderByDesc('period')
            ->get();

        return ApiResponse::data(CommissionPoolResource::collection($pools), [
            'branchId' => (string) $branch->getKey(),
            'branchName' => $branch->name,
        ]);
    }

    /**
     * POST /api/v1/commission/generate — Finance computes the period's pools.
     *
     * Branch profit is read off the ledger (§8's income − expense per branch),
     * so a pool is a consequence of the books rather than a figure somebody
     * typed in.
     */
    public function generate(GenerateCommissionRequest $request, GenerateCommissionPoolsAction $action): JsonResponse
    {
        $this->authorize('generate', CommissionPool::class);

        $period = (string) $request->validated('period');
        $pools = $action->handle($period, $this->actor($request));

        return ApiResponse::data(
            CommissionPoolResource::collection($pools),
            [
                'period' => $period,
                'blockedByLoss' => $pools->reject(fn (CommissionPool $p): bool => $p->isDistributable())->count(),
                'ledgerPosting' => 'none — commission is expensed on the payroll entry (§5)',
            ],
            Response::HTTP_CREATED,
        );
    }
}

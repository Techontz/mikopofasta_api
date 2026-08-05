<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Domain\Accounting\Actions\ClosePeriodAction;
use App\Domain\Accounting\Policies\AccountingPolicy;
use App\Domain\Accounting\Services\PeriodResultCalculator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ClosePeriodRequest;
use App\Http\Resources\AccountingPeriodResource;
use App\Models\AccountingPeriod;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Month-end close — Decision Register D1.
 *
 * Three endpoints: the list of closed periods, a preview of what closing one
 * would produce, and the close itself.
 *
 * The preview exists because closing is irreversible. There is no reopen — D1
 * puts reserve appropriation inside the close, and reopening would mean
 * un-appropriating reserve Admin may already have released. So Finance is given
 * a way to see the figures before committing to them.
 */
final class AccountingPeriodController extends Controller
{
    /** GET /api/v1/accounting/periods */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAccounting('viewPeriods', $request);

        $periods = AccountingPeriod::query()
            ->with(['closer', 'branchResults.branch'])
            ->orderByDesc('period')
            ->get();

        return ApiResponse::data(AccountingPeriodResource::collection($periods));
    }

    /**
     * GET /api/v1/accounting/periods/{period}/preview
     *
     * What a close would recognise, without recognising it. Reads through the
     * same calculator the close uses, so the preview cannot disagree with the
     * result.
     */
    public function preview(Request $request, string $period, PeriodResultCalculator $calculator): JsonResponse
    {
        $this->authorizeAccounting('viewPeriods', $request);

        $result = $calculator->forPeriod($period);

        $branches = [];

        foreach ($result->branchIds() as $branchId) {
            $branches[] = [
                'branchId' => $branchId === null ? null : (string) $branchId,
                'incomeTotal' => $result->incomeTotal($branchId, allBranches: false)->toDecimalString(),
                'expenseTotal' => $result->expenseTotal($branchId, allBranches: false)->toDecimalString(),
                'realisedProfit' => $result->profit($branchId, allBranches: false)->toDecimalString(),
            ];
        }

        return ApiResponse::data([
            'period' => $period,
            'alreadyClosed' => AccountingPeriod::isClosed($period),
            'incomeTotal' => $result->incomeTotal()->toDecimalString(),
            'expenseTotal' => $result->expenseTotal()->toDecimalString(),
            'realisedProfit' => $result->profit()->toDecimalString(),
            'branches' => $branches,
        ]);
    }

    /** POST /api/v1/accounting/periods/close */
    public function close(ClosePeriodRequest $request, ClosePeriodAction $action): JsonResponse
    {
        $this->authorizeAccounting('closePeriod', $request);

        $period = $action->handle(
            (string) $request->validated('period'),
            $this->actor($request),
            $request->validated('notes') === null ? null : (string) $request->validated('notes'),
        );

        return ApiResponse::data(
            new AccountingPeriodResource($period->load(['closer', 'branchResults.branch'])),
            status: Response::HTTP_CREATED,
        );
    }

    /**
     * AccountingPolicy covers the module and is not bound to a single model, so
     * it is called directly rather than through $this->authorize() — the same
     * pattern AuthorizesCapital follows.
     */
    private function authorizeAccounting(string $ability, Request $request): void
    {
        abort_unless(app(AccountingPolicy::class)->{$ability}($this->actor($request)), Response::HTTP_FORBIDDEN);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hr;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\UpdatePayrollSettingRequest;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * HRM → Payroll Settings — the rates payroll and commission apply.
 *
 *   staffFundPercentage        withheld from base salary into the Staff Fund
 *   commissionPoolPercentage   the share of distributable branch profit that
 *                              becomes the staff commission pool
 *   hqHoldPercentage           HQ's cut of branch profit, before distribution
 *   zoneOverridePercentage     a zone manager's share of their branches' pools
 *
 * A change applies to the next payroll or commission run that is generated.
 * Runs already generated keep the figures they were computed with — the
 * payslip holds the deduction amount and the pool holds its own percentages.
 */
final class PayrollSettingController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /api/v1/payroll-settings */
    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PayrollRun::class);

        return ApiResponse::data($this->payload(PayrollSetting::singleton()));
    }

    /**
     * PUT /api/v1/payroll-settings
     *
     * Audited: these rates decide what every employee takes home and how much
     * of each branch's profit is paid out, and "who changed the fund to 20%,
     * and when" is the first question anybody would ask.
     */
    public function update(UpdatePayrollSettingRequest $request): JsonResponse
    {
        $this->authorize('manageSettings', PayrollRun::class);
        $actor = $this->actor($request);

        $setting = PayrollSetting::singleton();
        $before = $setting->rates()->toRow();

        DB::transaction(function () use ($setting, $request, $actor, $before): void {
            $setting->fill([
                'staff_fund_percentage' => (string) $request->validated('staffFundPercentage'),
                'commission_pool_percentage' => (string) $request->validated('commissionPoolPercentage'),
                'hq_hold_percentage' => (string) $request->validated('hqHoldPercentage'),
                'zone_override_percentage' => (string) $request->validated('zoneOverridePercentage'),
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->audit->log(
                AuditAction::PayrollSettingUpdated,
                $setting,
                before: $before,
                after: $setting->rates()->toRow(),
                actor: $actor,
            );
        });

        return ApiResponse::data($this->payload($setting->fresh()));
    }

    /** @return array<string, mixed> */
    private function payload(PayrollSetting $setting): array
    {
        return [
            'staffFundPercentage' => $setting->staff_fund_percentage,
            'commissionPoolPercentage' => $setting->commission_pool_percentage,
            'hqHoldPercentage' => $setting->hq_hold_percentage,
            'zoneOverridePercentage' => $setting->zone_override_percentage,
            'updatedAt' => $setting->updated_at?->toIso8601String(),
            'updatedByName' => $setting->editor?->name,
        ];
    }
}

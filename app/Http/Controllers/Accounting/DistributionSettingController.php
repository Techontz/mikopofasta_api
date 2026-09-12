<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Treasury\Concerns\AuthorizesCapital;
use App\Http\Requests\Accounting\UpdateDistributionSettingRequest;
use App\Models\DistributionSetting;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The profit split — Capital → Profit Distribution.
 *
 * How much of distributable profit is reinvested into Principal and how much
 * is set aside for shareholders (ACCOUNT OVERVIEW §I.16). Read by anyone who
 * may see treasury; changed only by an administrator, because it decides what
 * shareholders are owed.
 *
 * Deliberately *not* filed under Settings. The Settings menu reproduces the
 * legacy system's own list verbatim and adding to it would change a workflow
 * operators have used for years; this is a capital-management concept and sits
 * with Shareholders and Contributions, where it belongs.
 */
final class DistributionSettingController extends Controller
{
    /* The same gate Shareholders and Contributions already use — this screen
       sits beside them, so it is authorised like them. */
    use AuthorizesCapital;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET /api/v1/distribution-setting
     */
    public function show(Request $request): JsonResponse
    {
        $this->authorizeCapital('view', $request);

        return ApiResponse::data($this->payload(DistributionSetting::singleton()));
    }

    /**
     * PUT /api/v1/distribution-setting
     *
     * Audited rather than silently saved: this is the rule that decides how
     * much of the company's earnings shareholders receive, and "who changed it
     * to 90/10, and when" is the first question anybody would ask.
     */
    public function update(UpdateDistributionSettingRequest $request): JsonResponse
    {
        $this->authorizeCapital('manage', $request);
        $actor = $this->actor($request);

        $setting = DistributionSetting::singleton();

        $before = [
            'reinvestment_percentage' => $setting->reinvestment_percentage,
            'dividend_percentage' => $setting->dividend_percentage,
        ];

        DB::transaction(function () use ($setting, $request, $actor, $before): void {
            $setting->fill([
                'reinvestment_percentage' => (string) $request->validated('reinvestmentPercentage'),
                'dividend_percentage' => (string) $request->validated('dividendPercentage'),
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->audit->log(
                AuditAction::DistributionSettingUpdated,
                $setting,
                before: $before,
                after: [
                    'reinvestment_percentage' => $setting->reinvestment_percentage,
                    'dividend_percentage' => $setting->dividend_percentage,
                ],
                actor: $actor,
            );
        });

        return ApiResponse::data($this->payload($setting->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(DistributionSetting $setting): array
    {
        return [
            'reinvestmentPercentage' => $setting->reinvestment_percentage,
            'dividendPercentage' => $setting->dividend_percentage,
            'updatedAt' => $setting->updated_at?->toIso8601String(),
            'updatedByName' => $setting->editor?->name,
        ];
    }
}

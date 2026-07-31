<?php

declare(strict_types=1);

namespace App\Domain\Loans\Actions;

use App\Domain\Loans\DTOs\PenaltySettingData;
use App\Enums\AuditAction;
use App\Models\PenaltySetting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Records the organisation-wide penalty default (POST /penalty-settings).
 *
 * This does not re-price anything. Live loans carry their own
 * `penalty_rate_snapshot` and the overdue job reads that; see the boundary note
 * in docs/modules/loan-charges.md.
 */
final class CreatePenaltySettingAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PenaltySettingData $data, User $actor): PenaltySetting
    {
        return DB::transaction(function () use ($data, $actor): PenaltySetting {
            $setting = PenaltySetting::query()->create([
                'calculation_type' => $data->calculationType,
                'amount' => $data->amount,
                'created_by' => $actor->id,
            ]);

            $this->audit->log(
                AuditAction::PenaltySettingCreated,
                $setting,
                after: ['calculation_type' => $setting->calculation_type->value, 'amount' => $setting->amount],
                actor: $actor,
            );

            return $setting;
        });
    }
}

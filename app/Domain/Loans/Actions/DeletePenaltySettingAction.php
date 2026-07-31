<?php

declare(strict_types=1);

namespace App\Domain\Loans\Actions;

use App\Enums\AuditAction;
use App\Models\PenaltySetting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Removes a penalty default (DELETE /penalty-settings/{penaltySetting}).
 */
final class DeletePenaltySettingAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PenaltySetting $setting, User $actor): void
    {
        DB::transaction(function () use ($setting, $actor): void {
            $this->audit->log(
                AuditAction::PenaltySettingDeleted,
                $setting,
                before: ['calculation_type' => $setting->calculation_type->value, 'amount' => $setting->amount],
                actor: $actor,
            );

            $setting->delete();
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Loans\Actions;

use App\Enums\AuditAction;
use App\Models\ReserveSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Sets the reserve percentage (PUT /reserve-setting).
 *
 * Singleton: the row is fetched or created, never inserted a second time, so
 * the screen can always assume exactly one value exists.
 */
final class UpdateReserveSettingAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(string $percentage, User $actor): ReserveSetting
    {
        return DB::transaction(function () use ($percentage, $actor): ReserveSetting {
            $setting = ReserveSetting::singleton();
            $before = ['percentage' => $setting->percentage];

            $setting->fill(['percentage' => $percentage, 'updated_by' => $actor->id])->save();

            $this->audit->log(
                AuditAction::ReserveSettingUpdated,
                $setting,
                before: $before,
                after: ['percentage' => $setting->percentage],
                actor: $actor,
            );

            return $setting;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Hr\DTOs\PayrollRates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The payroll and commission rates — HRM → Payroll Settings.
 *
 * These were constants on PayrollCalculator and CommissionCalculator. The
 * client sets them, so they are stored policy now.
 *
 * Singleton: exactly one row, the same pattern `ReserveSetting` and
 * `DistributionSetting` use. What a rate *was* for a past month is kept where
 * it was applied — on the payroll line's deduction amount and on the commission
 * pool's percentages — so changing a rate never rewrites history.
 *
 * @property int $id
 * @property string $staff_fund_percentage
 * @property string $commission_pool_percentage
 * @property string $hq_hold_percentage
 * @property string $zone_override_percentage
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PayrollSetting extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'staff_fund_percentage',
        'commission_pool_percentage',
        'hq_hold_percentage',
        'zone_override_percentage',
        'updated_by',
    ];

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The one row, created on first read so no screen has to cope with its
     * absence. The defaults repeat the values the constants held.
     */
    public static function singleton(): self
    {
        return static::query()->firstOrCreate([], PayrollRates::defaults()->toRow());
    }

    public function rates(): PayrollRates
    {
        return new PayrollRates(
            staffFund: $this->staff_fund_percentage,
            commissionPool: $this->commission_pool_percentage,
            hqHold: $this->hq_hold_percentage,
            zoneOverride: $this->zone_override_percentage,
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'staff_fund_percentage' => 'decimal:3',
            'commission_pool_percentage' => 'decimal:3',
            'hq_hold_percentage' => 'decimal:3',
            'zone_override_percentage' => 'decimal:3',
        ];
    }
}

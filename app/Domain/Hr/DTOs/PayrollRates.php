<?php

declare(strict_types=1);

namespace App\Domain\Hr\DTOs;

use App\Support\Percentage;

/**
 * The rates the payroll and commission engines apply — HRM → Payroll Settings.
 *
 * A value rather than a model so the calculators stay pure: they are handed
 * the rates, they never go and read them. The container builds one from
 * `PayrollSetting` (see AppServiceProvider); a unit test builds one directly.
 */
final readonly class PayrollRates
{
    public const string DEFAULT_STAFF_FUND = '10.000';

    public const string DEFAULT_COMMISSION_POOL = '20.000';

    public const string DEFAULT_HQ_HOLD = '2.000';

    public const string DEFAULT_ZONE_OVERRIDE = '5.000';

    public function __construct(
        /** Withheld from BASE salary into the Staff Fund. */
        public string $staffFund,
        /** The share of distributable branch profit that becomes the staff pool. */
        public string $commissionPool,
        /** HQ's cut of branch profit, taken before anything is distributable. */
        public string $hqHold,
        /** A zone manager's override on the pools of the branches they oversee. */
        public string $zoneOverride,
    ) {}

    public static function defaults(): self
    {
        return new self(
            staffFund: self::DEFAULT_STAFF_FUND,
            commissionPool: self::DEFAULT_COMMISSION_POOL,
            hqHold: self::DEFAULT_HQ_HOLD,
            zoneOverride: self::DEFAULT_ZONE_OVERRIDE,
        );
    }

    public function staffFundPercentage(): Percentage
    {
        return Percentage::of($this->staffFund);
    }

    public function commissionPoolPercentage(): Percentage
    {
        return Percentage::of($this->commissionPool);
    }

    public function hqHoldPercentage(): Percentage
    {
        return Percentage::of($this->hqHold);
    }

    public function zoneOverridePercentage(): Percentage
    {
        return Percentage::of($this->zoneOverride);
    }

    /** @return array<string, string> */
    public function toRow(): array
    {
        return [
            'staff_fund_percentage' => $this->staffFund,
            'commission_pool_percentage' => $this->commissionPool,
            'hq_hold_percentage' => $this->hqHold,
            'zone_override_percentage' => $this->zoneOverride,
        ];
    }
}

<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

enum Duration: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Legacy day-count used by the live system (1 / 7 / 30).
     */
    public function days(): int
    {
        return match ($this) {
            self::Daily => 1,
            self::Weekly => 7,
            self::Monthly => 30,
        };
    }

    public function addPeriods(CarbonImmutable $date, int $periods): CarbonImmutable
    {
        return match ($this) {
            self::Daily => $date->addDays($periods),
            self::Weekly => $date->addWeeks($periods),
            self::Monthly => $date->addDays(30 * $periods),
        };
    }
}

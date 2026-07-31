<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Enums;

/** Mirrors the frontend's TRIGGERED_BY — `penalty_runs.triggered_by` (§2.6). */
enum TriggeredBy: string
{
    case Cron = 'cron';
    case Manual = 'manual';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

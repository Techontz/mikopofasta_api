<?php

declare(strict_types=1);

namespace App\Domain\Reports\Enums;

use App\Domain\Hr\Enums\PerformanceRating;

/**
 * Days-past-due buckets — §15.6's "DPD buckets + A/B/C/D scoring", mirroring
 * the frontend's DPD_BUCKETS and `dpdBucket()`.
 *
 * The boundaries live here and nowhere else. A loan that landed in "1–7 days"
 * on one report and "8–30 days" on another would make both untrustworthy, and
 * the surest way to prevent that is to leave no second place to define them.
 */
enum DpdBucket: string
{
    case OnTime = 'on_time';
    case SlightDelay = 'slight_delay';
    case Risk = 'risk';
    case Default = 'default';

    public static function forDays(int $daysPastDue): self
    {
        return match (true) {
            $daysPastDue <= 0 => self::OnTime,
            $daysPastDue <= 7 => self::SlightDelay,
            $daysPastDue <= 30 => self::Risk,
            default => self::Default,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OnTime => 'On time',
            self::SlightDelay => '1–7 days',
            self::Risk => '8–30 days',
            self::Default => '30+ days',
        };
    }

    /**
     * §15.6's A/B/C/D scoring, aligned to the same boundaries.
     *
     * Reusing the buckets rather than defining separate score bands is what
     * keeps "in the 8–30 bucket" and "rated C" the same statement about the
     * same borrower.
     */
    public function rating(): PerformanceRating
    {
        return match ($this) {
            self::OnTime => PerformanceRating::A,
            self::SlightDelay => PerformanceRating::B,
            self::Risk => PerformanceRating::C,
            self::Default => PerformanceRating::D,
        };
    }

    /**
     * Whether this bucket counts toward Portfolio at Risk.
     *
     * PAR is conventionally measured at 30 days; §15.6's age analysis
     * headlines "Portfolio at Risk (8+ days)", the boundary the frontend
     * chose, and it is kept so the two agree.
     */
    public function isAtRisk(): bool
    {
        return $this === self::Risk || $this === self::Default;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Customers\Enums;

/**
 * The verdict a face scan reached.
 *
 * Two cases and no third. A scan that was abandoned halfway is not a record —
 * nothing is submitted until the scanner has either completed the sequence or
 * decided it cannot, so there is no "pending" state to represent.
 */
enum FaceScanStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';

    public function isPassed(): bool
    {
        return $this === self::Passed;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

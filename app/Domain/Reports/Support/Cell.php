<?php

declare(strict_types=1);

namespace App\Domain\Reports\Support;

/**
 * Formats a report cell whose value may legitimately be absent.
 *
 * Exists so that "this record has no branch" reads the same in every report
 * rather than as a null here, an empty string there and a dash somewhere else.
 * The nullable parameter is also what lets a caller write
 * `Cell::text($loan->customer?->fullName())` — the null-coalescing happens
 * against a value the type system knows can be null, instead of against a
 * relation static analysis believes never is.
 */
final class Cell
{
    /** What an absent value looks like in every report. */
    public const string NONE = '—';

    public static function text(?string $value): string
    {
        return ($value === null || $value === '') ? self::NONE : $value;
    }

    /**
     * A label for a value that is absent because the thing has not happened
     * yet, rather than because it is unknown.
     */
    public static function pending(?string $value, string $pendingLabel): string
    {
        return ($value === null || $value === '') ? $pendingLabel : $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Hr\Enums;

/**
 * Why a hand-entered deduction was withheld — HRM → Deductions.
 *
 * A penalty is somebody's decision about somebody else's conduct, and "what
 * kind of conduct" is the first question the employee, and later an auditor,
 * will ask. Negligence (uzembe) and a loss the employee caused (hasara) are the
 * two the client named; anything else is recorded as Other, with the reason
 * saying what.
 */
enum DeductionCategory: string
{
    case Negligence = 'negligence';
    case Loss = 'loss';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Negligence => 'Negligence (Uzembe)',
            self::Loss => 'Loss caused (Hasara)',
            self::Other => 'Other',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

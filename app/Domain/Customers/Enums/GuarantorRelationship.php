<?php

declare(strict_types=1);

namespace App\Domain\Customers\Enums;

/**
 * Mirrors the frontend's GUARANTOR_RELATIONSHIPS. Shared by guarantors and
 * next-of-kin, exactly as the frontend shares it.
 */
enum GuarantorRelationship: string
{
    case Spouse = 'spouse';
    case Parent = 'parent';
    case Sibling = 'sibling';
    case Relative = 'relative';
    case Friend = 'friend';
    case Colleague = 'colleague';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

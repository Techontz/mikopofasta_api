<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

/**
 * Where a reserve utilisation request stands — Decision Register D1.
 *
 * The same three-state shape float transfers and expense requests already use,
 * deliberately: Finance raises, Admin decides, and money moves only on
 * approval. Reusing the shape means the approval screens behave the way every
 * other approval screen in the system behaves.
 */
enum ReserveUtilisationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

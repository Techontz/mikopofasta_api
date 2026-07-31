<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Enums;

/**
 * Where a headquarters movement has got to.
 *
 * The column stays a VARCHAR rather than a database ENUM, and deliberately so:
 * the original migration notes that the legacy status vocabulary was never
 * captured, because both screens that would show it were photographed with no
 * rows. Constraining the column would freeze a guess into the schema.
 *
 * These three are the rebuilt frontend's (`APPROVAL_STATUSES` in
 * types/operations.ts) and are what new records use. When legacy rows are
 * imported, whatever they carry will still fit the column, and mapping them
 * becomes a data question rather than a migration.
 */
enum HqTransactionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    public function isDecidable(): bool
    {
        return $this === self::Pending;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}

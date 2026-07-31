<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Enums;

/**
 * Which register an expense belongs to.
 *
 * The legacy system keeps two, and they are genuinely separate books rather
 * than a filter over one: Expenses → Register Branch Expenses lists the names
 * a branch may spend against, Headquarters Expenses → Register Expenses lists
 * head office's, and neither screen shows the other's. The reporting spec
 * depends on the split too — its Expense Tagging Report exists precisely to
 * detect a cost booked to the wrong one.
 *
 * The values mirror the frontend's `ExpenseName["scope"]` in
 * types/operations.ts. types/expense.ts spells the second one `hq`; the
 * operational screens are the ones that read it on every row, so their
 * spelling is the wire format and the admin screen maps.
 */
enum ExpenseScope: string
{
    case Branch = 'branch';
    case Headquarters = 'headquarters';

    public function label(): string
    {
        return match ($this) {
            self::Branch => 'Branch',
            self::Headquarters => 'Headquarters',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}

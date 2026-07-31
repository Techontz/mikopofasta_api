<?php

declare(strict_types=1);

namespace App\Domain\Organization\Enums;

/**
 * Mirrors the frontend's BRANCH_TYPES (types/enums.ts) and the `branches.type`
 * ENUM in backend spec §2.2.
 *
 * A `sub` branch rolls up into a `main` branch through `parent_branch_id` for
 * reporting purposes (§12).
 */
enum BranchType: string
{
    case Main = 'main';
    case Sub = 'sub';

    public function label(): string
    {
        return match ($this) {
            self::Main => 'Main Branch',
            self::Sub => 'Sub Branch',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}

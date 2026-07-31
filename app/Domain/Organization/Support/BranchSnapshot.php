<?php

declare(strict_types=1);

namespace App\Domain\Organization\Support;

use App\Models\Branch;

/**
 * The before/after shape written into `audit_logs` for a branch.
 *
 * Kept in one place so a "before" and an "after" row are always directly
 * comparable — a diff between two differently-shaped snapshots is worse than
 * useless, because it reads as a change that never happened.
 */
final class BranchSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function of(Branch $branch): array
    {
        return [
            'name' => $branch->name,
            'region_id' => $branch->region_id,
            'zone_id' => $branch->zone_id,
            'phone' => $branch->phone,
            'type' => $branch->type->value,
            'parent_branch_id' => $branch->parent_branch_id,
            'is_head_office' => $branch->is_head_office,
            'status' => $branch->status->value,
        ];
    }
}

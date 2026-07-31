<?php

declare(strict_types=1);

namespace App\Domain\Organization\Services;

use App\Models\Branch;
use Illuminate\Support\Collection;

/**
 * Branch tree traversal and integrity — backend spec §12.
 *
 * The hierarchy is `parent_branch_id` self-referencing, so it is a forest of
 * arbitrary depth. Two things have to be true of it at all times, and both are
 * enforced here rather than trusted:
 *
 *   1. No cycles. A cycle makes every ancestor/descendant walk non-terminating,
 *      which surfaces as a hung request rather than a visible error.
 *   2. A branch is never its own parent — the degenerate one-node cycle.
 */
final class BranchHierarchy
{
    /**
     * Would setting `$parentId` as the parent of `$branch` create a cycle?
     *
     * True when the proposed parent is the branch itself, or sits anywhere
     * beneath it in the tree.
     */
    public function wouldCreateCycle(Branch $branch, ?int $parentId): bool
    {
        if ($parentId === null) {
            return false;
        }

        if ($parentId === $branch->id) {
            return true;
        }

        return in_array($parentId, $branch->selfAndDescendantIds(), true);
    }

    /**
     * The full tree, as roots with nested children.
     *
     * Loads every branch once and assembles the tree in memory: the recursive
     * relation walk would otherwise issue a query per node, and the branch
     * count is small enough that one query is always the right trade.
     *
     * @param Collection<int, Branch> $branches
     * @return list<array{branch: Branch, depth: int, children: list<mixed>}>
     */
    public function tree(Collection $branches): array
    {
        $byParent = $branches->groupBy(
            static fn (Branch $branch): string => (string) ($branch->parent_branch_id ?? 'root'),
        );

        return $this->build($byParent, 'root', 0);
    }

    /**
     * Flattens the tree to a depth-annotated list, parents before children —
     * the order an indented branch table renders in.
     *
     * @param Collection<int, Branch> $branches
     * @return list<array{branch: Branch, depth: int}>
     */
    public function flatten(Collection $branches): array
    {
        $flat = [];

        $walk = function (array $nodes) use (&$walk, &$flat): void {
            foreach ($nodes as $node) {
                $flat[] = ['branch' => $node['branch'], 'depth' => $node['depth']];
                $walk($node['children']);
            }
        };

        $walk($this->tree($branches));

        return $flat;
    }

    /**
     * @param Collection<string, Collection<int, Branch>> $byParent
     * @return list<array{branch: Branch, depth: int, children: list<mixed>}>
     */
    private function build(Collection $byParent, string $parentKey, int $depth): array
    {
        $children = $byParent->get($parentKey);

        if ($children === null) {
            return [];
        }

        return $children
            ->sortBy('name')
            ->values()
            ->map(fn (Branch $branch): array => [
                'branch' => $branch,
                'depth' => $depth,
                'children' => $this->build($byParent, (string) $branch->id, $depth + 1),
            ])
            ->all();
    }
}

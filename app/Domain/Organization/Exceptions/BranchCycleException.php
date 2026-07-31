<?php

declare(strict_types=1);

namespace App\Domain\Organization\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A branch cannot be its own ancestor.
 *
 * Not a rule from the specification but a structural invariant of the
 * `parent_branch_id` tree: a cycle makes ancestor and descendant traversal
 * non-terminating, so a request that touched the affected branch would hang
 * rather than fail.
 */
final class BranchCycleException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            'A branch cannot roll up into itself or into one of its own sub-branches.',
            ErrorCode::BranchHierarchyCycle,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['parentBranchId' => ['A branch cannot roll up into itself or into one of its own sub-branches.']],
        );
    }
}

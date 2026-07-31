<?php

declare(strict_types=1);

namespace App\Domain\Organization\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec §13: "Attempting to access another branch's record without the
 * appropriate scope or explicit permission returns 403 with
 * error_code: BRANCH_SCOPE_VIOLATION, logged to audit_logs — cross-branch
 * snooping attempts are themselves an auditable event."
 *
 * The audit row is written by the caller (see BranchScopeGuard), because an
 * exception constructor is the wrong place to perform IO.
 */
final class BranchScopeViolationException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            "You don't have access to this branch's records.",
            ErrorCode::BranchScopeViolation,
            Response::HTTP_FORBIDDEN,
        );
    }
}

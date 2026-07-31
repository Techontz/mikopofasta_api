<?php

declare(strict_types=1);

namespace App\Domain\Organization\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Head Office branch cannot be deleted. Mirrors the frontend's
 * deleteBranch guard ("Can't delete the Head Office branch.").
 *
 * HQ is where every HQ-scoped role is based and what company_profiles points
 * at (§12 Decision 2); removing it would strand both.
 */
final class HeadOfficeProtectedException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            "Can't delete the Head Office branch.",
            ErrorCode::HeadOfficeProtected,
            Response::HTTP_CONFLICT,
        );
    }
}

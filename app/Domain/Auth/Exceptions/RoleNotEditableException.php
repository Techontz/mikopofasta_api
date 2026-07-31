<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super Admin always holds every permission. Mirrors setRolePermissions() in
 * config/permissions.ts, whose comment explains why: so an administrator can
 * never lock everyone out.
 */
final class RoleNotEditableException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            "Super Admin always holds every permission — it can't be edited.",
            ErrorCode::RoleNotEditable,
            Response::HTTP_CONFLICT,
        );
    }
}

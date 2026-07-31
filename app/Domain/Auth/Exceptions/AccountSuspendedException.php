<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended user's credentials may well be correct — they are simply not
 * allowed in. Mirrors the frontend's findUserByPhone(), which only ever
 * returns users whose status is "active".
 */
final class AccountSuspendedException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            'This account has been suspended. Contact an administrator.',
            ErrorCode::AccountSuspended,
            Response::HTTP_FORBIDDEN,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reset delivery is by email, but `users.email` is nullable (spec §2.1) while
 * authentication is by phone. A user provisioned without an email address
 * therefore has no self-service reset path and must be reset by an
 * administrator. See the Phase 2 notes in README.md.
 */
final class PasswordResetUnavailableException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            'This account has no email address on file, so a reset link cannot be sent. Contact an administrator.',
            ErrorCode::PasswordResetUnavailable,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}

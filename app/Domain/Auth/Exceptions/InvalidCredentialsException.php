<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deliberately identical whether the phone is unknown or the password is
 * wrong — distinguishing them would let an attacker enumerate valid accounts.
 * Mirrors the frontend's single "Invalid phone number or password." message.
 */
final class InvalidCredentialsException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            'Invalid phone number or password.',
            ErrorCode::InvalidCredentials,
            Response::HTTP_UNAUTHORIZED,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

final class InvalidResetTokenException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            'This password reset link is invalid or has expired.',
            ErrorCode::InvalidResetToken,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}

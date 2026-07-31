<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

final class CurrentPasswordIncorrectException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            'Your current password is incorrect.',
            ErrorCode::CurrentPasswordIncorrect,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['current_password' => ['Your current password is incorrect.']],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Customers\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A NIDA number identifies exactly one person. Mirrors the frontend's
 * "A customer with this NIDA number is already registered."
 */
final class CustomerAlreadyRegisteredException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            'A customer with this NIDA number is already registered.',
            ErrorCode::CustomerAlreadyRegistered,
            Response::HTTP_CONFLICT,
            ['nidaNumber' => ['A customer with this NIDA number is already registered.']],
        );
    }
}

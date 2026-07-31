<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/** What the Bank module refuses to move, and why. */
final class BankMovementInvalidException extends DomainException
{
    public static function notPending(): self
    {
        return new self(
            'Only a pending movement can be decided.',
            ErrorCode::ValidationFailed,
            Response::HTTP_CONFLICT,
        );
    }

    /**
     * The ledger would allow this — an asset account may go negative — but a
     * real bank account may not, and a system that let one would be reporting
     * money the company does not have.
     */
    public static function insufficientFunds(string $account, string $available, string $required): self
    {
        return new self(
            "{$account} holds {$available}, which is less than the {$required} this movement needs.",
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function inactiveAccount(string $account): self
    {
        return new self(
            "{$account} is not active, so nothing can be posted to it.",
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function wrongDestination(string $expected): self
    {
        return new self(
            $expected,
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function sameAccount(): self
    {
        return new self(
            'The destination must differ from the source account.',
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}

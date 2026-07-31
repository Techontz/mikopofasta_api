<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/** What the Headquarters Transaction module refuses, and why. */
final class HqTransactionInvalidException extends DomainException
{
    /**
     * Each direction names the sides it has: money arriving names where it
     * landed, money leaving names where it came from, and a transfer between
     * two pots names both. Anything else is not a movement anyone can post.
     */
    public static function wrongSides(string $expected): self
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
            'A transfer must move money between two different accounts.',
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function notPending(): self
    {
        return new self(
            'Only a pending transaction can be decided.',
            ErrorCode::ValidationFailed,
            Response::HTTP_CONFLICT,
        );
    }

    /**
     * A pot cannot be overdrawn.
     *
     * `hq_accounts.balance` is a stored figure carried over from the legacy
     * system, so this is the only thing standing between it and a negative
     * headquarters balance that no entry explains.
     */
    public static function insufficientBalance(string $account, string $available): self
    {
        return new self(
            "{$account} holds only {$available}, which is less than this transaction moves.",
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}

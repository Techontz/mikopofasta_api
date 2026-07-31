<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/** Everything the Expenses module refuses to do, and why. */
final class ExpenseException extends DomainException
{
    public static function categoryInUse(string $name): self
    {
        return new self(
            "{$name} has expenses filed against it and cannot be removed. Requests already filed keep their category.",
            ErrorCode::ResourceInUse,
            Response::HTTP_CONFLICT,
        );
    }

    public static function duplicateCategory(string $name): self
    {
        return new self(
            "{$name} is already on this register.",
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * The category and the register disagree — a branch request naming a
     * headquarters category, or the reverse. Refused rather than silently
     * re-scoped, because the Expense Tagging Report exists to find exactly this
     * mistake and it should never have to.
     */
    public static function scopeMismatch(): self
    {
        return new self(
            'That expense name belongs to the other register.',
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function branchRequired(): self
    {
        return new self(
            'A branch expense must name the branch that bears the cost.',
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function notPending(): self
    {
        return new self(
            'Only a pending request can be decided.',
            ErrorCode::ValidationFailed,
            Response::HTTP_CONFLICT,
        );
    }

    public static function alreadyPosted(): self
    {
        return new self(
            'This request has already been paid and cannot be deleted. Reverse the ledger entry instead.',
            ErrorCode::ResourceInUse,
            Response::HTTP_CONFLICT,
        );
    }
}

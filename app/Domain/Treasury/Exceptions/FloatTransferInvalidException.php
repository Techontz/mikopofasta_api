<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/** A float transfer that cannot be resolved to two real ledger accounts. */
final class FloatTransferInvalidException extends DomainException
{
    public static function noHeadOffice(): self
    {
        return new self(
            'No head office branch is configured, so company float has nowhere to come from.',
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function missingBranch(string $side): self
    {
        return new self(
            "The {$side} branch could not be found.",
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function missingAccount(string $side): self
    {
        return new self(
            "The {$side} account could not be found.",
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function notPending(): self
    {
        return new self(
            'Only a pending transfer can be decided.',
            ErrorCode::ValidationFailed,
            Response::HTTP_CONFLICT,
        );
    }

    public static function alreadyPosted(): self
    {
        return new self(
            'This transfer has already moved money and cannot be deleted. Reverse it in the ledger instead.',
            ErrorCode::ResourceInUse,
            Response::HTTP_CONFLICT,
        );
    }
}

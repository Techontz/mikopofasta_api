<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/** An account that cannot be closed, and why. */
final class BankAccountInUseException extends DomainException
{
    public static function hasBalance(string $name, string $balance): self
    {
        return new self(
            "{$name} still holds {$balance}. Transfer the balance out before closing it.",
            ErrorCode::ResourceInUse,
            Response::HTTP_CONFLICT,
        );
    }

    public static function hasPendingMovements(string $name): self
    {
        return new self(
            "{$name} has transactions or transfers awaiting a decision. Settle those first.",
            ErrorCode::ResourceInUse,
            Response::HTTP_CONFLICT,
        );
    }
}

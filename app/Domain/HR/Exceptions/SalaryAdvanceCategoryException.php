<?php

declare(strict_types=1);

namespace App\Domain\Hr\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/** What the salary advance bands refuse, and why. */
final class SalaryAdvanceCategoryException extends DomainException
{
    public static function duplicateName(string $name): self
    {
        return new self(
            "{$name} is already a salary advance category.",
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * Two bands covering one amount would price the same request two ways,
     * decided by row order — which nobody intends as a pricing policy.
     */
    public static function overlaps(string $name, string $from, string $to): self
    {
        return new self(
            "This band overlaps {$name} ({$from} – {$to}). Bands must not cover the same amount.",
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function inUse(string $name): self
    {
        return new self(
            "{$name} has advances still in progress and cannot be removed. Retire it once they are recovered.",
            ErrorCode::ResourceInUse,
            Response::HTTP_CONFLICT,
        );
    }
}

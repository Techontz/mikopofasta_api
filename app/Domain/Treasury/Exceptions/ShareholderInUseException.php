<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A shareholder with capital recorded against them cannot be removed.
 *
 * The FK is RESTRICT, so the database would refuse anyway — but as a raw
 * integrity violation, i.e. a 500. Catching it first turns it into a 409 that
 * says why.
 */
final class ShareholderInUseException extends DomainException
{
    public static function hasContributions(int $count): self
    {
        return new self(
            sprintf(
                "Can't delete — %d capital %s recorded against this shareholder.",
                $count,
                $count === 1 ? 'contribution is' : 'contributions are',
            ),
            ErrorCode::ResourceInUse,
            Response::HTTP_CONFLICT,
        );
    }
}

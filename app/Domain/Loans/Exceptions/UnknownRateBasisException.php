<?php

declare(strict_types=1);

namespace App\Domain\Loans\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A product names an interest rate basis no class implements.
 *
 * The same refusal as UnknownInterestFormulaException and for the same reason:
 * the basis decides whether "24%" is charged monthly or annually, so falling
 * back to a default would misprice the loan by a factor of twelve while
 * producing a schedule that looks entirely ordinary.
 *
 * A product with NO basis is a different case and is not an error — see
 * RateBasisRegistry.
 */
final class UnknownRateBasisException extends DomainException
{
    /**
     * @param list<string> $available
     */
    public static function for(string $code, array $available): self
    {
        return new self(
            sprintf(
                'No implementation exists for the interest rate basis [%s]. Available bases: %s.',
                $code,
                $available === [] ? 'none are registered' : implode(', ', $available),
            ),
            ErrorCode::UnknownRateBasis,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}

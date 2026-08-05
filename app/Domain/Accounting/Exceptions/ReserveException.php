<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use App\Support\Money;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards on spending the Reserve — Decision Register D1.
 *
 * D1 gives the fund two protections: Admin approval, and the plain arithmetic
 * that you cannot spend what has not been appropriated. The first is a policy;
 * this class is the second.
 */
final class ReserveException extends DomainException
{
    private function __construct(string $message, ErrorCode $code)
    {
        parent::__construct($message, $code, Response::HTTP_CONFLICT);
    }

    /**
     * Checked at approval rather than at request, because the balance that
     * matters is the one on the day the money moves. Two requests raised
     * against a sufficient balance can both be pending and only one affordable.
     */
    public static function insufficient(Money $requested, Money $available): self
    {
        return new self(
            sprintf(
                'The Reserve fund holds %s; %s cannot be released.',
                $available->toDecimalString(),
                $requested->toDecimalString(),
            ),
            ErrorCode::InsufficientReserve,
        );
    }

    public static function notPending(string $reference): self
    {
        return new self(
            sprintf('Reserve utilisation %s has already been decided.', $reference),
            ErrorCode::InvalidReserveUtilisationState,
        );
    }
}

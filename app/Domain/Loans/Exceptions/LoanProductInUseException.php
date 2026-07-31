<?php

declare(strict_types=1);

namespace App\Domain\Loans\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * §15's standard CRUD guards for a product that still has live loans.
 */
final class LoanProductInUseException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message, ErrorCode::ResourceInUse, Response::HTTP_CONFLICT);
    }

    public static function hasOpenLoans(int $count): self
    {
        return new self(sprintf(
            "Can't delete — %d non-closed %s reference this product.",
            $count,
            $count === 1 ? 'loan references' : 'loans',
        ));
    }

    public static function structuralChangeBlocked(int $count): self
    {
        return new self(sprintf(
            'The interest formula and mandate requirement cannot be changed while %d active %s use this product.',
            $count,
            $count === 1 ? 'loan uses' : 'loans',
        ));
    }
}

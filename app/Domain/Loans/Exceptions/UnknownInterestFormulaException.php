<?php

declare(strict_types=1);

namespace App\Domain\Loans\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A product names an interest formula no strategy implements.
 *
 * Raised rather than defaulted. A registry that quietly fell back to a known
 * formula would price the loan by an arithmetic nobody chose, and the resulting
 * schedule would look entirely ordinary — the error would surface months later
 * as an unexplained variance, if at all.
 *
 * The message names what IS available, because the administrator who hit this
 * needs to fix a configuration row and the list is the fix.
 */
final class UnknownInterestFormulaException extends DomainException
{
    /**
     * @param list<string> $available
     */
    public static function for(string $code, array $available): self
    {
        return new self(
            sprintf(
                'No interest strategy implements the formula [%s]. Available formulas: %s.',
                $code,
                $available === [] ? 'none are registered' : implode(', ', $available),
            ),
            ErrorCode::UnknownInterestFormula,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}

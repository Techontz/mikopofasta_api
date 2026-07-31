<?php

declare(strict_types=1);

namespace App\Domain\Loans\Exceptions;

use App\Domain\Loans\Enums\LoanStatus;
use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A status change the §10 state machine does not permit.
 */
final class IllegalLoanTransitionException extends DomainException
{
    public function __construct(LoanStatus $from, LoanStatus $to)
    {
        parent::__construct(
            sprintf('Cannot move a loan from %s to %s.', $from->value, $to->value),
            ErrorCode::IllegalLoanTransition,
            Response::HTTP_CONFLICT,
        );
    }
}

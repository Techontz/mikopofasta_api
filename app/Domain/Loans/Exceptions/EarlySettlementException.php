<?php

declare(strict_types=1);

namespace App\Domain\Loans\Exceptions;

use App\Domain\Loans\Enums\LoanStatus;
use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use App\Support\Money;
use Symfony\Component\HttpFoundation\Response;

/**
 * The loan cannot be settled early, or the money offered does not cover it.
 *
 * The shortfall case names both figures. An officer told only "not enough" has
 * to work out how much more to ask the customer for while the customer is
 * standing there.
 */
final class EarlySettlementException extends DomainException
{
    private function __construct(string $message, int $status = Response::HTTP_CONFLICT)
    {
        parent::__construct($message, ErrorCode::InvalidLoanState, $status);
    }

    public static function notSettleable(LoanStatus $status): self
    {
        return new self(sprintf(
            'A loan that is %s cannot be settled early. Only a live loan can be closed this way.',
            strtolower($status->label()),
        ));
    }

    public static function noSchedule(): self
    {
        return new self('This loan has no repayment schedule yet, so there is nothing to settle.');
    }

    public static function nothingOutstanding(): self
    {
        return new self('This loan owes nothing, so there is nothing to settle early.');
    }

    public static function shortfall(Money $required, Money $tendered): self
    {
        return new self(
            sprintf(
                'Settling this loan today needs %s and %s was tendered — %s short.',
                $required->toDecimalString(),
                $tendered->toDecimalString(),
                $required->subtract($tendered)->toDecimalString(),
            ),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}

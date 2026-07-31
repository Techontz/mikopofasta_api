<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The payment or suspense item is not in a state where the action makes sense.
 */
final class PaymentStateException extends DomainException
{
    private function __construct(string $message, ErrorCode $code)
    {
        parent::__construct($message, $code, Response::HTTP_CONFLICT);
    }

    public static function suspenseAlreadyResolved(): self
    {
        return new self(
            'This suspense item has already been allocated.',
            ErrorCode::SuspenseAlreadyResolved,
        );
    }

    public static function loanNotRepayable(string $loanNumber, string $status): self
    {
        return new self(
            sprintf('Loan %s is %s and cannot take a repayment.', $loanNumber, $status),
            ErrorCode::LoanNotRepayable,
        );
    }

    public static function notAwaitingConfirmation(): self
    {
        return new self(
            'This payment is not awaiting confirmation.',
            ErrorCode::InvalidPaymentState,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Loans\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/** Why a disbursement cannot be paid from the account chosen for it. */
final class DisbursementFundingException extends DomainException
{
    public static function accountCannotSend(string $account): self
    {
        return new self(
            "{$account} is not an active account the company pays out from.",
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function noFundingAccount(): self
    {
        return new self(
            'No company Bank or Mobile Money account is registered for payouts. Register one, or pay from branch cash.',
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * The payout, plus what other loans already have in flight from the same
     * account, is more than the account holds.
     */
    public static function insufficientFunds(string $account, string $available, string $required): self
    {
        return new self(
            "{$account} has {$available} available for payouts, which is less than the {$required} this disbursement pays out.",
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}

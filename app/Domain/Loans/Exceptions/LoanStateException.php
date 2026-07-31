<?php

declare(strict_types=1);

namespace App\Domain\Loans\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The loan is not in a state where the requested action makes sense.
 *
 * Messages mirror the frontend's wording in features/loans/actions.ts.
 */
final class LoanStateException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message, ErrorCode::InvalidLoanState, Response::HTTP_CONFLICT);
    }

    public static function notAwaitingManagerApproval(): self
    {
        return new self('This loan is not awaiting manager approval.');
    }

    public static function selfApproval(): self
    {
        return new self("You can't approve an application you submitted yourself.");
    }

    public static function notAwaitingMandateOtp(): self
    {
        return new self('This loan is not awaiting a mandate OTP.');
    }

    public static function noFailedMandate(): self
    {
        return new self('This loan has no failed mandate to retry.');
    }

    public static function notInCreditReview(): self
    {
        return new self('This loan is not in credit review.');
    }

    public static function notAwaitingDisbursement(): self
    {
        return new self('This loan is not ready for disbursement.');
    }

    public static function noFailedDisbursement(): self
    {
        return new self('This loan has no failed disbursement to retry.');
    }

    public static function disbursementAttemptsExhausted(int $max): self
    {
        return new self(sprintf(
            'Disbursement has failed %d times and has been escalated for a manual decision.',
            $max,
        ));
    }

    public static function disbursementAlreadySettled(string $reference): self
    {
        return new self(sprintf(
            'Disbursement batch %s has already been settled; a repeated callback is ignored.',
            $reference,
        ));
    }

    /**
     * A callback naming a loan with nothing in flight — the frontend's "This
     * loan has no disbursement in flight."
     */
    public static function noPendingDisbursement(string $loanNumber): self
    {
        return new self(sprintf('Loan %s has no disbursement in flight.', $loanNumber));
    }

    public static function cannotDeleteAfterApproval(): self
    {
        return new self('An application can only be withdrawn before it is approved.');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\CustomerAdvances\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/** The customer salary advance is not at the step being attempted. */
final class CustomerAdvanceStateException extends DomainException
{
    /**
     * Conflict by default, because most of these are "the advance is not at
     * that step" — a state problem rather than a bad request. The status is a
     * parameter so a genuine validation failure can still say 422.
     */
    private function __construct(string $message, ErrorCode $code, int $status = Response::HTTP_CONFLICT)
    {
        parent::__construct($message, $code, $status);
    }

    public static function alreadyInProgress(): self
    {
        return new self(
            'This customer already has a salary advance in progress.',
            ErrorCode::AdvanceInProgress,
        );
    }

    public static function notAwaitingDecision(): self
    {
        return new self(
            'This advance is not awaiting a decision.',
            ErrorCode::InvalidAdvanceState,
        );
    }

    public static function notApproved(): self
    {
        return new self(
            'Only an approved advance can be disbursed.',
            ErrorCode::InvalidAdvanceState,
        );
    }

    public static function notCollectable(): self
    {
        return new self(
            'Only a disbursed advance can take a payment.',
            ErrorCode::InvalidAdvanceState,
        );
    }

    /**
     * No band covers the amount asked for.
     *
     * Refused rather than defaulted to some band: an advance priced by a
     * category that does not cover it would carry terms nobody agreed.
     */
    public static function noCategoryForAmount(string $amount): self
    {
        return new self(
            "No salary advance category covers {$amount}. Add a band that includes it before requesting.",
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * More than the advance still owes.
     *
     * Refused rather than banked as a credit, which is what a loan does with a
     * surplus. A loan has a schedule with later instalments for the extra to
     * sit against; an advance has one balance, and money accepted past it would
     * be a liability to the customer with no record built to hold it.
     */
    public static function overpayment(string $outstanding): self
    {
        return new self(
            "This advance only has {$outstanding} outstanding. Collect that amount or less.",
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /** The customer has no branch, so the advance has no ledger dimension. */
    public static function customerHasNoBranch(): self
    {
        return new self(
            'This customer is not attached to a branch, so an advance cannot be booked for them.',
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}

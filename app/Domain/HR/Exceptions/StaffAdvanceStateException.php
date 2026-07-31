<?php

declare(strict_types=1);

namespace App\Domain\Hr\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The staff advance is not at the step being attempted.
 *
 * §11: request → approval (HR) → disbursement (Finance only, never HR).
 */
final class StaffAdvanceStateException extends DomainException
{
    /**
     * Conflict by default, because most of these are "the advance is not at
     * that step" — a state problem rather than a bad request. The status is a
     * parameter rather than fixed so a genuine validation failure can say 422;
     * see noCategoryForAmount.
     */
    private function __construct(string $message, ErrorCode $code, int $status = Response::HTTP_CONFLICT)
    {
        parent::__construct($message, $code, $status);
    }

    public static function alreadyInProgress(): self
    {
        return new self(
            'This staff member already has an advance in progress.',
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

    /**
     * No band covers the amount asked for.
     *
     * Refused rather than defaulted to some band: an advance priced by a
     * category that does not cover it would carry terms nobody agreed, and
     * silently applying the nearest one would misprice it in whichever
     * direction the gap happened to fall.
     */
    public static function noCategoryForAmount(string $amount): self
    {
        return new self(
            "No salary advance category covers {$amount}. Add a band that includes it before requesting.",
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}

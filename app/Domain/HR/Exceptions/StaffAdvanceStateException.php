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
    private function __construct(string $message, ErrorCode $code)
    {
        parent::__construct($message, $code, Response::HTTP_CONFLICT);
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
}

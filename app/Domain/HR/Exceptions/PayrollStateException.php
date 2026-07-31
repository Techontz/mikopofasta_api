<?php

declare(strict_types=1);

namespace App\Domain\Hr\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The payroll run is not in a state where the action makes sense.
 *
 * §11's sequence is draft → finalized → paid, and each step is a different
 * person's authority. Attempting them out of order is a workflow error rather
 * than bad input, which is why these are 409s and not 422s.
 */
final class PayrollStateException extends DomainException
{
    private function __construct(string $message, ErrorCode $code)
    {
        parent::__construct($message, $code, Response::HTTP_CONFLICT);
    }

    public static function periodAlreadyRun(string $period): self
    {
        return new self(
            sprintf('A payroll run for %s already exists.', $period),
            ErrorCode::PayrollPeriodExists,
        );
    }

    public static function notDraft(): self
    {
        return new self('Only a draft run can be finalized.', ErrorCode::InvalidPayrollState);
    }

    public static function notFinalized(): self
    {
        return new self('Only a finalized run can be paid.', ErrorCode::InvalidPayrollState);
    }

    /**
     * A run with no lines would finalize to nothing and post nothing, leaving
     * a "finalized" period that paid no one.
     */
    public static function noLines(): self
    {
        return new self('This run has no payroll lines.', ErrorCode::PayrollEmpty);
    }

    public static function noActiveStaff(): self
    {
        return new self(
            'There are no active staff to pay for this period.',
            ErrorCode::PayrollEmpty,
        );
    }
}

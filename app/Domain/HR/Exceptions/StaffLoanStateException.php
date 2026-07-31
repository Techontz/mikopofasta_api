<?php

declare(strict_types=1);

namespace App\Domain\Hr\Exceptions;

use App\Domain\Hr\Enums\StaffLoanStatus;
use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The staff loan is not at the step being attempted, or the fund cannot cover
 * it.
 *
 * §14 and §16.7–16.8: request → approval (HR) → disbursement (Finance only,
 * never HR) → recovery from payroll.
 */
final class StaffLoanStateException extends DomainException
{
    /**
     * Conflict by default, because most of these are "the loan is not at that
     * step" — a state problem rather than a bad request.
     */
    private function __construct(string $message, ErrorCode $code, int $status = Response::HTTP_CONFLICT)
    {
        parent::__construct($message, $code, $status);
    }

    public static function alreadyInProgress(): self
    {
        return new self(
            'This staff member already has a loan in progress.',
            ErrorCode::StaffLoanInProgress,
        );
    }

    /**
     * Names both states rather than saying "invalid".
     *
     * Somebody reading this is usually looking at a screen that offered them
     * the button, so what they need to know is which step the loan is actually
     * at — most often because another person decided it first.
     */
    public static function wrongStatus(StaffLoanStatus $actual, StaffLoanStatus $expected): self
    {
        return new self(
            sprintf(
                'This loan is %s; that step needs it to be %s.',
                mb_strtolower($actual->label()),
                mb_strtolower($expected->label()),
            ),
            ErrorCode::InvalidStaffLoanState,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Hr\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/** What may not be changed about somebody's pay, and why. */
final class StaffPayException extends DomainException
{
    /**
     * §16.1 — "Salary haiwezi kubadilishwa baada ya approval."
     *
     * Granting an allowance or recording a penalty against a month whose
     * payroll is already approved would change what somebody is paid after the
     * figures were agreed. The message names the period and its state, because
     * the next thing the person wants to know is whether to wait for next month
     * or ask for the run to be reopened.
     */
    public static function periodClosed(string $period, string $status): self
    {
        return new self(
            sprintf('Payroll for %s is already %s and its figures can no longer change.', $period, mb_strtolower($status)),
            ErrorCode::InvalidPayrollState,
            Response::HTTP_CONFLICT,
        );
    }

    public static function allowanceAlreadyGranted(string $type): self
    {
        return new self(
            sprintf('This staff member already draws a recurring %s allowance.', $type),
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}

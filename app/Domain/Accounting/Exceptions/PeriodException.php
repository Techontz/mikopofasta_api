<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The rules that make a close safe to run exactly once — Decision Register D1.
 *
 * All four refuse at the service layer rather than in the UI. Closing is not an
 * idempotent report: it recognises profit and appropriates reserve, and doing
 * either twice would double both.
 */
final class PeriodException extends DomainException
{
    private function __construct(string $message, ErrorCode $code, int $status = Response::HTTP_CONFLICT)
    {
        parent::__construct($message, $code, $status);
    }

    public static function alreadyClosed(string $period): self
    {
        return new self(
            sprintf('Period %s is already closed. Correct it with a reversal in a later period.', $period),
            ErrorCode::PeriodAlreadyClosed,
        );
    }

    /**
     * A period cannot be closed while it is still being traded in.
     *
     * The client's rule is that profit is measured by date and the new month
     * starts afresh; closing mid-month would recognise a fortnight's income as
     * if it were the month's, and the remaining fortnight would then have
     * nowhere to go.
     */
    public static function notEnded(string $period): self
    {
        return new self(
            sprintf('Period %s has not ended yet and cannot be closed.', $period),
            ErrorCode::PeriodNotEnded,
        );
    }

    /**
     * Periods close in order.
     *
     * Out of order, an earlier close would sweep income the later close had
     * already swept — the accounts are cumulative, and the two would fight over
     * the same money.
     */
    public static function priorPeriodOpen(string $period, string $prior): self
    {
        return new self(
            sprintf('Period %s cannot be closed while %s is still open. Close periods in order.', $period, $prior),
            ErrorCode::PriorPeriodOpen,
        );
    }

    public static function empty(string $period): self
    {
        return new self(
            sprintf('Period %s has no income or expense activity to close.', $period),
            ErrorCode::PeriodEmpty,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Loans\Exceptions;

use App\Domain\Loans\Enums\LoanStatus;
use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards on taking a loan off the book — §5's Write-Off and Recovered Loans.
 *
 * Writing off is the one operation in this system that reduces what borrowers
 * owe without anybody paying, so every guard here is about making it hard to do
 * by accident and impossible to do twice.
 */
final class WriteOffException extends DomainException
{
    private function __construct(string $message, ErrorCode $code)
    {
        parent::__construct($message, $code, Response::HTTP_CONFLICT);
    }

    /**
     * Only a defaulted loan may be written off.
     *
     * §5 puts write-off at the end of a progression — arrears, then default,
     * then written off — and skipping to the end from `active` would forgive a
     * loan nobody had established was uncollectable.
     */
    public static function notEligible(string $loanNumber, LoanStatus $status): self
    {
        return new self(
            sprintf(
                'Loan %s is %s. Only a defaulted loan may be written off.',
                $loanNumber,
                $status->value,
            ),
            ErrorCode::LoanNotWriteOffEligible,
        );
    }

    public static function alreadyWrittenOff(string $loanNumber): self
    {
        return new self(
            sprintf('Loan %s has already been written off.', $loanNumber),
            ErrorCode::LoanAlreadyWrittenOff,
        );
    }

    /**
     * A recovery needs something to recover.
     *
     * Money arriving against a loan that was never written off is an ordinary
     * repayment, and recording it here would credit Recovered Loans while
     * leaving the receivable outstanding.
     */
    public static function notWrittenOff(string $loanNumber): self
    {
        return new self(
            sprintf('Loan %s has not been written off; record this as a repayment instead.', $loanNumber),
            ErrorCode::LoanNotWrittenOff,
        );
    }
}

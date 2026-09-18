<?php

declare(strict_types=1);

namespace App\Domain\Reversals\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every refusal the transaction-reversal workflow can issue.
 *
 * These read as sentences an approver can act on, because they surface in a
 * dialog at the moment somebody is deciding whether money moves back. "Not
 * permitted" tells them nothing; "this loan has 3 payments against it" tells
 * them what to do next.
 */
final class ReversalRefusedException extends DomainException
{
    private function __construct(string $message, ErrorCode $code = ErrorCode::ReversalNotPermitted)
    {
        parent::__construct($message, $code, Response::HTTP_CONFLICT);
    }

    public static function alreadyPending(string $subject): self
    {
        return new self(sprintf('A reversal request for %s is already pending.', $subject));
    }

    public static function notPending(): self
    {
        return new self('This reversal request has already been decided.');
    }

    /**
     * §14's separation of duties. The same rule as loan approval, and for the
     * same reason: one person must not be able to move money and bless the
     * movement.
     */
    public static function selfApproval(): self
    {
        return new self("You can't approve a reversal you requested yourself.");
    }

    public static function paymentAlreadyReversed(string $reference): self
    {
        return new self(sprintf('Payment %s has already been reversed.', $reference));
    }

    /**
     * A payment whose cash has been banked and reconciled is no longer this
     * module's to unwind — the deposit would have to be unwound with it, and
     * that is the reconciliation screen's job.
     */
    public static function paymentNotReversible(string $reference, string $status): self
    {
        return new self(sprintf(
            'Payment %s is %s and cannot be reversed from here.',
            $reference,
            str_replace('_', ' ', $status),
        ));
    }

    /**
     * The ruling behind the disbursement guard: a disbursement may be undone
     * only while nothing has happened on top of it. Once the borrower has
     * paid, the payments have to come off first — one at a time, each with its
     * own approval — or the correction is a write-off, not a reversal.
     */
    public static function disbursementHasRepayments(string $loanNumber, int $count): self
    {
        return new self(sprintf(
            'Loan %s has %d payment(s) against it. Reverse those first, or use a write-off.',
            $loanNumber,
            $count,
        ));
    }

    public static function disbursementNotSettled(string $reference): self
    {
        return new self(sprintf('Batch %s was never settled, so there is nothing to reverse.', $reference));
    }

    public static function disbursementAlreadyReversed(string $reference): self
    {
        return new self(sprintf('Batch %s has already been reversed.', $reference));
    }

    public static function loanNotReversible(string $loanNumber, string $status): self
    {
        return new self(sprintf(
            'Loan %s is %s. Only an active loan with no repayments can have its disbursement reversed.',
            $loanNumber,
            str_replace('_', ' ', $status),
        ));
    }

    public static function noPenaltyOutstanding(int $installment): self
    {
        return new self(sprintf('Installment %d has no outstanding penalty to reverse.', $installment));
    }

    /**
     * A collected penalty is income the books have recognised. Taking it back
     * means reversing the PAYMENT that collected it, which is a different
     * request with a different subject.
     */
    public static function penaltyAlreadyCollected(int $installment): self
    {
        return new self(sprintf(
            'The penalty on installment %d has been paid. Reverse the payment that collected it instead.',
            $installment,
        ));
    }

    /**
     * An early settlement waived unbilled interest and cancelled the
     * installments it had not reached. Taking its cash back alone would leave
     * those installments cancelled with principal still owed on them — a loan
     * the books call closed while the borrower owes money. Undoing the
     * settlement itself is a restructure somebody signs for, not a reversal.
     */
    public static function paymentSettledLoanEarly(string $reference, string $loanNumber): self
    {
        return new self(sprintf(
            'Payment %s settled loan %s early and cancelled its remaining installments. '
            .'It cannot be reversed on its own — contact the system administrator to restructure the loan.',
            $reference,
            $loanNumber,
        ));
    }

    public static function advancePaymentAlreadyReversed(string $reference): self
    {
        return new self(sprintf('Salary advance payment %s has already been reversed.', $reference));
    }

    public static function advanceNotReversible(string $reference, string $status): self
    {
        return new self(sprintf(
            'Salary advance %s is %s, so its payments cannot be reversed.',
            $reference,
            $status,
        ));
    }

    public static function entryIsReversal(): self
    {
        return new self("A reversal entry can't itself be reversed.");
    }

    public static function entryAlreadyReversed(string $entryNumber): self
    {
        return new self(
            sprintf('Entry %s has already been reversed.', $entryNumber),
            ErrorCode::EntryAlreadyReversed,
        );
    }

    public static function subjectMissing(string $type): self
    {
        return new self(sprintf('A %s reversal must name what it is reversing.', $type));
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use App\Support\Money;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards on confirming banked cash — §7's second trust state.
 *
 * Reconciliation is the moment a teller's word becomes the bank's, so the
 * checks are arithmetic rather than procedural: the payments named must exist,
 * must still be awaiting verification, and must sum to what was actually
 * banked. A mismatch is §7's "amount mismatch → investigation", refused here
 * rather than reconciled optimistically and queried later.
 */
final class ReconciliationException extends DomainException
{
    private function __construct(string $message, ErrorCode $code)
    {
        parent::__construct($message, $code, Response::HTTP_CONFLICT);
    }

    public static function alreadyReconciled(int $depositId): self
    {
        return new self(
            sprintf('Cash deposit #%d has already been reconciled.', $depositId),
            ErrorCode::InvalidPaymentState,
        );
    }

    public static function amountMismatch(Money $banked, Money $payments): self
    {
        return new self(
            sprintf(
                'The deposit banks %s but the payments named total %s. Investigate before confirming.',
                $banked->toDecimalString(),
                $payments->toDecimalString(),
            ),
            ErrorCode::InvalidPaymentState,
        );
    }

    /**
     * Every payment must still be awaiting verification.
     *
     * A payment already confirmed on another deposit would otherwise be
     * confirmed twice, and the second `Dr Bank · Cr Teller Cash` would move
     * money out of a till that no longer holds it.
     */
    public static function notPending(string $paymentReference): self
    {
        return new self(
            sprintf('Payment %s is not awaiting verification.', $paymentReference),
            ErrorCode::InvalidPaymentState,
        );
    }

    public static function noPayments(int $depositId): self
    {
        return new self(
            sprintf('Cash deposit #%d names no payments to confirm.', $depositId),
            ErrorCode::InvalidPaymentState,
        );
    }
}

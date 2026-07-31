<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use App\Support\Money;
use Symfony\Component\HttpFoundation\Response;

/**
 * The posting engine refused an entry.
 *
 * Always a programming error rather than user input — by the time lines reach
 * LedgerService they were built by a posting builder, so an imbalance means a
 * builder is wrong. It surfaces as a 500-class fault deliberately: silently
 * accepting it would corrupt the ledger, which is the one thing §5 exists to
 * prevent.
 */
final class UnbalancedEntryException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct(
            $message,
            ErrorCode::UnbalancedJournalEntry,
            Response::HTTP_INTERNAL_SERVER_ERROR,
        );
    }

    public static function mismatch(string $context, Money $debits, Money $credits): self
    {
        return new self(sprintf(
            'Unbalanced journal entry [%s]: debits %s, credits %s.',
            $context,
            $debits->toDecimalString(),
            $credits->toDecimalString(),
        ));
    }

    public static function tooFewLines(string $context, int $count): self
    {
        return new self(sprintf(
            'Journal entry [%s] needs at least 2 lines, got %d.',
            $context,
            $count,
        ));
    }

    /**
     * @param list<int> $accountIds
     */
    public static function unpostableAccounts(string $context, array $accountIds): self
    {
        return new self(sprintf(
            'Journal entry [%s] references missing or inactive accounts: %s.',
            $context,
            implode(', ', $accountIds),
        ));
    }
}

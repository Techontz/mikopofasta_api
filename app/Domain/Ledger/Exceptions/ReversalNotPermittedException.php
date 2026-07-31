<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards on the reversal workflow. Messages mirror the frontend's wording in
 * features/ledger/actions.ts.
 */
final class ReversalNotPermittedException extends DomainException
{
    private function __construct(string $message, ErrorCode $code)
    {
        parent::__construct($message, $code, Response::HTTP_CONFLICT);
    }

    public static function isReversal(): self
    {
        return new self("A reversal entry can't itself be reversed.", ErrorCode::ReversalNotPermitted);
    }

    public static function alreadyReversed(string $entryNumber): self
    {
        return new self(
            sprintf('Entry %s has already been reversed.', $entryNumber),
            ErrorCode::EntryAlreadyReversed,
        );
    }

    public static function alreadyRequested(string $entryNumber): self
    {
        return new self(
            sprintf('A reversal request for %s is already pending.', $entryNumber),
            ErrorCode::ReversalNotPermitted,
        );
    }

    public static function notPending(): self
    {
        return new self('This reversal request has already been decided.', ErrorCode::ReversalNotPermitted);
    }

    public static function selfApproval(): self
    {
        return new self(
            "You can't approve a reversal you requested yourself.",
            ErrorCode::ReversalNotPermitted,
        );
    }
}

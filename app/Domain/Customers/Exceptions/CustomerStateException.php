<?php

declare(strict_types=1);

namespace App\Domain\Customers\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The customer is not in a state where the requested action makes sense.
 *
 * Messages mirror the frontend's own wording in features/customers/actions.ts
 * so the same words reach the user through either path.
 */
final class CustomerStateException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message, ErrorCode::InvalidCustomerState, Response::HTTP_CONFLICT);
    }

    public static function notAwaitingApproval(): self
    {
        return new self('This customer is not awaiting approval.');
    }

    public static function alreadyFrozen(): self
    {
        return new self('Customer is already frozen.');
    }

    public static function notFrozen(): self
    {
        return new self('Customer is not frozen.');
    }

    public static function frozenAccountCannotChangeStatus(): self
    {
        return new self('Unfreeze the account before changing its status.');
    }
}

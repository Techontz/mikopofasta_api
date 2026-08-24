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

    /**
     * Approving a registration that is not finished would put a manager's name
     * against a file nobody could yet assess — and would make the customer
     * loan-eligible with, for instance, no face scan on record.
     */
    public static function kycIncompleteForApproval(string $outstanding): self
    {
        return new self(
            'This registration is not complete and cannot be approved yet. Outstanding: '.$outstanding,
        );
    }

    /**
     * The separation of duties the loan chain already enforces, applied to the
     * registration that feeds it — see LoanApprovalWorkflow::selfApproval.
     *
     * A manager who registers a customer may not also be the one who approves
     * them; otherwise a single person can put a borrower on the book unseen,
     * which is the whole thing an approval stage exists to prevent.
     */
    public static function selfApproval(): self
    {
        return new self(
            'You registered this customer, so you cannot approve their registration. Another approver must decide.',
        );
    }

    /**
     * Only a returned registration can be sent back for a second look.
     */
    public static function notReturned(): self
    {
        return new self('This registration has not been returned for correction.');
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

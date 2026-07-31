<?php

declare(strict_types=1);

namespace App\Domain\Customers\Exceptions;

use App\Domain\Customers\Enums\GroupRole;
use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every way a group membership change can be refused.
 *
 * Each message says what is wrong and what would fix it, because these surface
 * verbatim in the officer's UI — "already in a group" without naming the
 * remedy just sends them to look for someone to ask.
 */
final class GroupException extends DomainException
{
    private function __construct(string $message, ErrorCode $code, int $status)
    {
        parent::__construct($message, $code, $status);
    }

    public static function branchMismatch(): self
    {
        return new self(
            'This customer is served from a different branch. A group meets in one place, so its members must share its branch.',
            ErrorCode::BranchScopeViolation,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function customerNotActive(): self
    {
        return new self(
            'Only an active customer can join a group. Approve or unfreeze them first.',
            ErrorCode::InvalidCustomerState,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function alreadyInAGroup(): self
    {
        return new self(
            'This customer already belongs to a group. Remove them from it before adding them here — group liability means one set of guarantors per customer.',
            ErrorCode::ResourceInUse,
            Response::HTTP_CONFLICT,
        );
    }

    public static function officeAlreadyHeld(GroupRole $role): self
    {
        return new self(
            sprintf(
                'This group already has a %s. Demote the current holder before appointing another.',
                strtolower($role->label()),
            ),
            ErrorCode::ResourceInUse,
            Response::HTTP_CONFLICT,
        );
    }

    public static function officerCannotLeave(GroupRole $role): self
    {
        return new self(
            sprintf(
                'The %s cannot be removed while holding office. Change their role to member first, so the group is not left without one.',
                strtolower($role->label()),
            ),
            ErrorCode::InvalidCustomerState,
            Response::HTTP_CONFLICT,
        );
    }

    public static function memberHasOpenLoan(): self
    {
        return new self(
            'This member still has an open loan booked to the group. It must be settled or written off before they leave.',
            ErrorCode::ResourceInUse,
            Response::HTTP_CONFLICT,
        );
    }

    public static function groupHasOpenLoans(): self
    {
        return new self(
            'This group still has money outstanding. Close or write off its loans before closing the group.',
            ErrorCode::ResourceInUse,
            Response::HTTP_CONFLICT,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Organization\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Raised when a delete is refused because something still references the row.
 *
 * Every FK in this schema is RESTRICT on delete (spec §2), so the database
 * would refuse anyway — but as a raw integrity-constraint violation, i.e. a
 * 500. Catching the condition first turns it into a 409 with a message that
 * says which relationship is in the way. The messages mirror the frontend's
 * own wording in features/admin/organization/*-actions.ts.
 */
final class OrganizationInUseException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message, ErrorCode::ResourceInUse, Response::HTTP_CONFLICT);
    }

    public static function branchHasUsers(int $count): self
    {
        return new self(sprintf(
            "Can't delete — %d %s still assigned to this branch.",
            $count,
            $count === 1 ? 'user is' : 'users are',
        ));
    }

    public static function branchHasChildren(int $count): self
    {
        return new self($count === 1
            ? "Can't delete — 1 sub-branch rolls up into this branch."
            : sprintf("Can't delete — %d sub-branches roll up into this branch.", $count));
    }

    public static function zoneHasBranches(): self
    {
        return new self("Can't delete — one or more branches are assigned to this zone.");
    }

    public static function zoneHasUsers(int $count): self
    {
        return new self(sprintf(
            "Can't delete — %d %s scoped to this zone.",
            $count,
            $count === 1 ? 'user is' : 'users are',
        ));
    }

    public static function regionHasBranches(): self
    {
        return new self("Can't delete — one or more branches are assigned to this region.");
    }

    public static function regionHasDistricts(): self
    {
        return new self("Can't delete — this region still has districts on record.");
    }

    public static function regionHasUsers(int $count): self
    {
        return new self(sprintf(
            "Can't delete — %d %s scoped to this region.",
            $count,
            $count === 1 ? 'user is' : 'users are',
        ));
    }
}

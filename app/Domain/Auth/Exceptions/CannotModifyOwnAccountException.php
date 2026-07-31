<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the frontend's rule "You can't change your own account status."
 * (features/admin/users/users-actions.ts). Without it, an administrator could
 * suspend or delete themselves and strip the system of its last operator.
 */
final class CannotModifyOwnAccountException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message, ErrorCode::CannotModifyOwnAccount, Response::HTTP_CONFLICT);
    }

    public static function status(): self
    {
        return new self("You can't change your own account status.");
    }

    public static function deletion(): self
    {
        return new self("You can't delete your own account.");
    }
}

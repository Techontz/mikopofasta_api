<?php

declare(strict_types=1);

namespace App\Domain\Customers\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mirrors the frontend's "Can't delete — customers are assigned to this
 * category."
 */
final class CategoryInUseException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message, ErrorCode::ResourceInUse, Response::HTTP_CONFLICT);
    }

    public static function hasCustomers(int $count): self
    {
        return new self(sprintf(
            "Can't delete — %d %s assigned to this category.",
            $count,
            $count === 1 ? 'customer is' : 'customers are',
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Base class for business-rule failures.
 *
 * Every domain exception carries a stable `ErrorCode` and the HTTP status it
 * should surface as, so the exception handler can render the spec §1 envelope
 * without a growing match statement of concrete exception classes.
 */
class DomainException extends RuntimeException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        string $message,
        public readonly ErrorCode $errorCode,
        public readonly int $status = Response::HTTP_UNPROCESSABLE_ENTITY,
        public readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

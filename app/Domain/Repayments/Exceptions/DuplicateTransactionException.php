<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * §15.3 documents this exactly: a repeated `transaction_id` returns
 * `409 { "error_code": "DUPLICATE_TRANSACTION" }`.
 */
final class DuplicateTransactionException extends DomainException
{
    public function __construct(string $transactionId)
    {
        parent::__construct(
            sprintf('Duplicate transaction %s — ignored.', $transactionId),
            ErrorCode::DuplicateTransaction,
            Response::HTTP_CONFLICT,
        );
    }
}

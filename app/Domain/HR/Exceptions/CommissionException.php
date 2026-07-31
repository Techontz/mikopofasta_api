<?php

declare(strict_types=1);

namespace App\Domain\Hr\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * §11's hard rule, refused at the service layer rather than merely hidden in
 * the UI: "commission_distributions for a branch/period cannot be created
 * while commission_pools.distributable_profit <= 0 (loss must be offset
 * first)."
 */
final class CommissionException extends DomainException
{
    private function __construct(string $message, ErrorCode $code)
    {
        parent::__construct($message, $code, Response::HTTP_CONFLICT);
    }

    public static function notDistributable(string $branchName, string $distributableProfit): self
    {
        return new self(
            sprintf(
                'Branch %s has a distributable profit of %s; the loss must be offset before any commission is shared.',
                $branchName,
                $distributableProfit,
            ),
            ErrorCode::CommissionNotDistributable,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Customers\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec §15.1 documents `422 INVALID_OTP` for this case.
 */
final class InvalidOtpException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            'Incorrect OTP. Please try again.',
            ErrorCode::InvalidOtp,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['otp' => ['Incorrect OTP. Please try again.']],
        );
    }
}

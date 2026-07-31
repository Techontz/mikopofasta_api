<?php

declare(strict_types=1);

namespace App\Domain\Customers\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The submitted `dynamic_form_data` does not satisfy the category's schema.
 *
 * Carries per-field errors keyed `dynamicFormData.<key>` so the wizard's
 * category step can highlight the offending input rather than showing a
 * page-level message.
 */
final class InvalidDynamicFormDataException extends DomainException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(array $errors)
    {
        parent::__construct(
            'The category information is incomplete or invalid.',
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $errors,
        );
    }
}

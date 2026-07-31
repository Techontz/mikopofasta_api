<?php

declare(strict_types=1);

namespace App\Domain\Loans\Exceptions;

use App\Domain\Loans\DTOs\EligibilityViolation;
use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * One or more §6 eligibility gates failed.
 *
 * Carries every violation, not just the first: an officer should see
 * everything wrong with an application at once. The primary code is promoted
 * to `error_code` so the frontend can switch on it — spec §15.2 documents
 * CATEGORY_NOT_ELIGIBLE_FOR_PRODUCT and CUSTOMER_FROZEN as distinct
 * client-visible outcomes.
 */
final class LoanNotEligibleException extends DomainException
{
    /**
     * @param list<EligibilityViolation> $violations
     */
    public function __construct(public readonly array $violations)
    {
        $primary = $violations[0] ?? null;

        parent::__construct(
            $primary === null
                ? 'This application does not meet the eligibility rules.'
                : $primary->message,
            self::codeFor($primary?->code),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['eligibility' => array_map(
                static fn (EligibilityViolation $v): string => $v->message,
                $violations,
            )],
        );
    }

    /**
     * Maps the violation code onto the §15.2 error-code vocabulary where one
     * exists, falling back to a generic code otherwise. The full list always
     * travels in `errors.eligibility`, so nothing is lost by the mapping.
     */
    private static function codeFor(?string $violationCode): ErrorCode
    {
        return match ($violationCode) {
            'CUSTOMER_FROZEN' => ErrorCode::CustomerFrozen,
            'KYC_INCOMPLETE' => ErrorCode::KycIncomplete,
            'CATEGORY_NOT_ELIGIBLE_FOR_PRODUCT' => ErrorCode::CategoryNotEligibleForProduct,
            'SCHEDULE_NOT_SUPPORTED_BY_PRODUCT' => ErrorCode::ScheduleNotSupportedByProduct,
            'GUARANTORS_REQUIRED' => ErrorCode::GuarantorsRequired,
            default => ErrorCode::LoanNotEligible,
        };
    }
}

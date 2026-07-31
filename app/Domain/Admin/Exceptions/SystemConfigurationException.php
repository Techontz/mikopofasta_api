<?php

declare(strict_types=1);

namespace App\Domain\Admin\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/** What system configuration refuses to change, and why. */
final class SystemConfigurationException extends DomainException
{
    /**
     * A repayment schedule a loan is running on.
     *
     * Refused rather than soft-deleted, because the schedule's
     * `frequency_days` is what generated that loan's installment dates. Take it
     * away and the loan's own schedule can no longer be explained, let alone
     * regenerated.
     */
    public static function scheduleHasLoans(string $name, int $count): self
    {
        return new self(
            "{$name} is in use by {$count} loan(s) and cannot be removed.",
            ErrorCode::ResourceInUse,
            Response::HTTP_CONFLICT,
        );
    }

    public static function scheduleOnProduct(string $name, string $product): self
    {
        return new self(
            "{$name} is offered by the {$product} product. Remove it there first.",
            ErrorCode::ResourceInUse,
            Response::HTTP_CONFLICT,
        );
    }

    public static function duplicateCode(string $code): self
    {
        return new self(
            "{$code} is already in use by another repayment schedule.",
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function duplicateName(string $name): self
    {
        return new self(
            "{$name} is already in use.",
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * A template body referring to something its event cannot supply.
     *
     * Caught when the template is saved rather than when it fires: an unknown
     * placeholder reaches the customer as a literal `{{amount}}`, and the
     * person who could fix it is the one writing the message, not the one
     * reading it.
     */
    /**
     * @param list<string> $unknown
     * @param list<string> $allowed
     */
    public static function unknownPlaceholders(array $unknown, array $allowed): self
    {
        return new self(
            sprintf(
                'This event cannot supply %s. Available: %s.',
                implode(', ', array_map(static fn (string $p): string => '{{'.$p.'}}', $unknown)),
                implode(', ', array_map(static fn (string $p): string => '{{'.$p.'}}', $allowed)),
            ),
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function templateAlreadyActive(string $event, string $channel): self
    {
        return new self(
            "An active {$channel} template already exists for {$event}. Deactivate it first.",
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function subjectOnSms(): self
    {
        return new self(
            'An SMS carries no subject line.',
            ErrorCode::ValidationFailed,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}

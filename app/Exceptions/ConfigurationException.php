<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform is not correctly initialised.
 *
 * Distinct from every other exception in this application, and deliberately so.
 * A DomainException means the request was wrong; a ConfigurationException means
 * the INSTALLATION is wrong, and no amount of retrying or correcting the request
 * will help. The operator needs to be told what to run.
 *
 * Surfaced as 503 rather than 500: the service is not broken, it is not ready.
 * That distinction matters to a load balancer, to an on-call engineer reading a
 * dashboard, and to anyone deciding whether to roll back a deploy or finish it.
 */
class ConfigurationException extends DomainException
{
    public static function systemUserMissing(): self
    {
        return new self(
            'System account has not been initialized. Run database seeders.',
            ErrorCode::SystemUserNotInitialized,
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    /**
     * The default account-type requirement profile is gone.
     *
     * The 2026_08_26 migration creates it, so reaching this means somebody
     * deleted the row by hand. It is fatal rather than defaulted-around: this
     * profile decides what KYC requires, and a system that guesses at that
     * silently marks customers complete on rules nobody chose.
     */
    public static function registrationRequirementsMissing(): self
    {
        return new self(
            'No default account type requirement profile exists. Registration cannot decide what is required until one is restored.',
            ErrorCode::RegistrationRequirementsMissing,
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    /**
     * More than one System account exists.
     *
     * The database unique index makes this unreachable on a healthy schema. It
     * is checked anyway because the failure it guards against is silent: two
     * system accounts means automated postings split between two identities,
     * and an audit trail that no longer answers "what did the automation do".
     */
    public static function systemUserDuplicated(int $count): self
    {
        return new self(
            sprintf(
                'System account is not unique — %d were found where there must be exactly one. Automated postings cannot be attributed until this is resolved.',
                $count,
            ),
            ErrorCode::SystemUserNotInitialized,
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}

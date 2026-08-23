<?php

declare(strict_types=1);

/**
 * Which external identity checks this deployment can actually perform.
 *
 * Availability is deployment configuration, not business policy. WHETHER a
 * customer must pass a NIDA check belongs to the institution and lives in
 * `account_type_requirements`; whether this installation is even able to run
 * one depends on whether a registry endpoint and credentials exist, which is a
 * property of the environment.
 *
 * Both default to FALSE, and that default is the truth today: there is no NIDA
 * registry integration and no SMS gateway. The registration flow reports that
 * plainly — "identity captured, external verification unavailable" — rather
 * than recording a verification that never happened. See KycEvaluator.
 *
 * Turning either on without also wiring the integration would be the one
 * genuinely dangerous change here: the checklist would start asking for a
 * verification nothing can produce, and every customer would stall.
 */
return [
    /*
     * The National Identification Authority registry lookup.
     *
     * When this is false the API still accepts a National ID number — it is
     * captured as an identity document like any other — but never stamps
     * `nida_verified_at`, because nothing checked it.
     */
    'nida' => [
        'available' => env('KYC_NIDA_AVAILABLE', false),
        'endpoint' => env('KYC_NIDA_ENDPOINT'),
    ],

    /*
     * The SMS gateway that would carry a one-time code to the customer's phone.
     *
     * A phone number is captured either way. `otp_verified_at` is only ever set
     * by a code that was actually delivered and returned.
     */
    'otp' => [
        'available' => env('KYC_OTP_AVAILABLE', false),
        'gateway' => env('KYC_OTP_GATEWAY'),
    ],
];

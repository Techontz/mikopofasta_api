<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Provider Webhook Signatures
    |--------------------------------------------------------------------------
    |
    | Spec §1: inbound callbacks are "verified via a provider-specific HMAC
    | signature header checked against a shared secret in config — never trust
    | an unsigned callback".
    |
    | One entry per provider. The `header` is what that provider sends; the
    | `secret` is the shared key. A provider whose secret is empty is treated
    | as MISCONFIGURED and its callbacks are rejected — never as "no signature
    | required", which would turn a deployment mistake into an open endpoint.
    |
    | `algorithm` is per-provider because providers differ; sha256 is the
    | common default.
    |
    */

    'providers' => [

        'payments' => [
            'header' => env('WEBHOOK_PAYMENTS_HEADER', 'X-Bank-Signature'),
            'secret' => env('WEBHOOK_PAYMENTS_SECRET'),
            'algorithm' => env('WEBHOOK_PAYMENTS_ALGO', 'sha256'),
        ],

        'vodacom' => [
            'header' => env('WEBHOOK_VODACOM_HEADER', 'X-Vodacom-Signature'),
            'secret' => env('WEBHOOK_VODACOM_SECRET'),
            'algorithm' => env('WEBHOOK_VODACOM_ALGO', 'sha256'),
        ],

    ],

    /*
    | How long a signed callback stays acceptable, in seconds, when the
    | provider sends a timestamp alongside the signature. Replaying a captured
    | callback after this window fails even though its signature is valid.
    |
    | Zero disables the check — appropriate only for a provider that sends no
    | timestamp, where idempotency is the sole replay defence.
    */
    'tolerance_seconds' => (int) env('WEBHOOK_TOLERANCE_SECONDS', 300),

    /*
    | The header carrying that timestamp, when the provider sends one.
    */
    'timestamp_header' => env('WEBHOOK_TIMESTAMP_HEADER', 'X-Webhook-Timestamp'),

];

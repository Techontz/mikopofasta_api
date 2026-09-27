<?php

/*
|--------------------------------------------------------------------------
| External integrations
|--------------------------------------------------------------------------
|
| Every external provider is accessed through a contract in app/Integrations/<Provider>
| with a "test" driver (realistic simulated behaviour, including failures) and a real
| driver selected by environment. No credentials are committed.
|
*/

return [
    'nida' => [
        'driver' => env('NIDA_DRIVER', 'test'),
        'base_url' => env('NIDA_BASE_URL'),
        'api_key' => env('NIDA_API_KEY'),
    ],
    'face' => [
        'driver' => env('FACE_DRIVER', 'test'),
        'base_url' => env('FACE_BASE_URL'),
        'api_key' => env('FACE_API_KEY'),
    ],
    'vodacom' => [
        'driver' => env('VODACOM_DRIVER', 'test'),
        'base_url' => env('VODACOM_BASE_URL'),
        'api_key' => env('VODACOM_API_KEY'),
        'callback_secret' => env('VODACOM_CALLBACK_SECRET'),
        'portal_url' => env('VODACOM_PORTAL_URL'),
        // Test driver disbursement result: success | failed | callback (wait for a signed callback).
        'test_outcome' => env('VODACOM_TEST_OUTCOME', 'success'),
        'max_disbursement_attempts' => 3,
    ],
    'bank_mandate' => [
        'driver' => env('BANK_MANDATE_DRIVER', 'test'),
        'base_url' => env('BANK_MANDATE_BASE_URL'),
        'api_key' => env('BANK_MANDATE_API_KEY'),
    ],
    'payments' => [
        'driver' => env('PAYMENTS_DRIVER', 'test'),
        'webhook_secret' => env('PAYMENTS_WEBHOOK_SECRET'),
        'company_id' => env('PAYMENTS_COMPANY_ID'),
    ],
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'sender_id' => env('SMS_SENDER_ID', 'M-KOPA'),
        'api_key' => env('SMS_API_KEY'),
    ],
];

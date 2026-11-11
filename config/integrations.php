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
        // Collection notifications (enhancement §4.2): off until the bank's payload is known. Set the secret, the company the
        // notifications belong to, the provider name and the mapping of our fields to the bank's (dot notation), e.g.
        // ['mandate_reference' => 'mandate.ref', 'payment_reference' => 'txn_id', 'amount' => 'amount', 'status' => 'status'].
        'webhook_secret' => env('BANK_MANDATE_WEBHOOK_SECRET'),
        'company_id' => env('BANK_MANDATE_COMPANY_ID'),
        'provider' => env('BANK_MANDATE_PROVIDER', 'bank'),
        'collection_fields' => null,
    ],
    // eSIMU (the Phone Bond loan method): smartphones enrolled with Linda Device Lock (Partner API v1,
    // https://api.linda.co.tz/v1/partner). Driver "http" for the live API, "test" for automated tests, "none" (default) refuses
    // every call so nothing is ever reported registered, enrolled, locked or unlocked. The key and the webhook signing secret
    // come from the Linda console (Developers) and live only in the server environment.
    'linda' => [
        'driver' => env('LINDA_DRIVER', 'none'),
        'base_url' => env('LINDA_BASE_URL', 'https://api.linda.co.tz/v1/partner'),
        'api_key' => env('LINDA_API_KEY'),
        'webhook_secret' => env('LINDA_WEBHOOK_SECRET'),
        'timeout' => (int) env('LINDA_TIMEOUT', 15),
        // Days after an instalment's due date before an unpaid instalment locks the phone: 0 = the morning after the due date.
        'grace_days' => (int) env('PHONE_BOND_GRACE_DAYS', 0),
        // Local time of the eSIMU evening warning and of the next-morning lock run.
        'timezone' => env('ESIMU_TIMEZONE', 'Africa/Dar_es_Salaam'),
        'warning_at' => env('ESIMU_WARNING_AT', '18:00'),
        'lock_at' => env('ESIMU_LOCK_AT', '08:00'),
        // Hours a lock / unlock may stay queued at Linda (phone off or out of signal) before staff are alerted.
        'command_alert_hours' => (int) env('ESIMU_COMMAND_ALERT_HOURS', 24),
    ],
    'payments' => [
        'driver' => env('PAYMENTS_DRIVER', 'test'),
        'webhook_secret' => env('PAYMENTS_WEBHOOK_SECRET'),
        'company_id' => env('PAYMENTS_COMPANY_ID'),
    ],
    /*
    | SMS: "log" (default) only writes to the application log; "messaging_service" sends through messaging-service.co.tz
    | with the account username / password (Basic auth). SMS_SENDER_ID must be a sender ID registered on that account.
    | SMS_TEST_MODE=true uses the provider's free test endpoint (no credits used, nothing delivered).
    */
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'sender_id' => env('SMS_SENDER_ID', 'M-KOPA'),
        'base_url' => env('SMS_BASE_URL', 'https://messaging-service.co.tz'),
        'username' => env('SMS_USERNAME'),
        'password' => env('SMS_PASSWORD'),
        'test_mode' => (bool) env('SMS_TEST_MODE', false),
        'api_key' => env('SMS_API_KEY'),
    ],
];

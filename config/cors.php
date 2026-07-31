<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'webhooks/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
     * Explicit allow-list only — never '*'. The Next.js frontend talks to this
     * API server-to-server (no CORS preflight at all), so this list exists for
     * browser-originated calls and should stay as small as possible.
     */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:3000'))),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'Idempotency-Key',
    ],

    /*
     * Idempotency-Key is echoed back so a client can confirm which key the
     * server replayed (backend spec §1).
     */
    'exposed_headers' => ['Idempotency-Key'],

    'max_age' => 3600,

    /*
     * Sanctum runs in token mode (Authorization: Bearer), not SPA cookie mode,
     * so no cross-site credentials are ever needed.
     */
    'supports_credentials' => false,

];

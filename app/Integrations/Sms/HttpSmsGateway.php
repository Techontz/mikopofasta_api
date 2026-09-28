<?php

namespace App\Integrations\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real SMS provider stub. Inferred: a JSON POST {sender_id, phone, message} with a bearer API key;
 * adjust the payload to the contracted provider when credentials are issued.
 */
class HttpSmsGateway implements SmsGateway
{
    public function send(string $phone, string $message): void
    {
        $config = config('integrations.sms');

        if (empty($config['api_key']) || empty($config['base_url'] ?? null)) {
            throw new RuntimeException('SMS provider is not configured.');
        }

        Http::withToken($config['api_key'])
            ->timeout(15)
            ->post(rtrim($config['base_url'], '/').'/messages', [
                'sender_id' => $config['sender_id'],
                'phone' => $phone,
                'message' => $message,
            ])
            ->throw();
    }
}

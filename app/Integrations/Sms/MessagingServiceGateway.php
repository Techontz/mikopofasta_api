<?php

namespace App\Integrations\Sms;

use App\Services\Sms\SmsSender;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * messaging-service.co.tz HTTP API (SMS_DRIVER=messaging_service): Basic auth with the account username and password,
 * JSON in and out. POST /api/sms/v1/text/single sends one message ({from, to, text}); with SMS_TEST_MODE=true the
 * provider's free test endpoint (/api/sms/v1/test/text/single) is used instead — dummy responses, no credits used.
 * A message the provider rejects (status group REJECTED: no credits, unregistered sender ID, invalid number…) throws,
 * so {@see SmsSender} records it as failed with the provider's reason.
 */
class MessagingServiceGateway implements ReportsSmsBalance, SmsGateway
{
    public function send(string $phone, string $message): void
    {
        $config = config('integrations.sms');
        $path = ($config['test_mode'] ?? false) ? '/api/sms/v1/test/text/single' : '/api/sms/v1/text/single';

        $response = $this->client()->post($path, [
            'from' => $config['sender_id'],
            'to' => SmsSender::normalisePhone($phone) ?? $phone,
            'text' => $message,
        ])->throw();

        $status = $response->json('messages.0.status');
        if (is_array($status) && strtoupper((string) ($status['groupName'] ?? '')) === 'REJECTED') {
            throw new RuntimeException(trim(($status['name'] ?? 'REJECTED').' - '.($status['description'] ?? '')));
        }
    }

    public function balance(): ?int
    {
        $balance = $this->client()->get('/api/sms/v1/balance')->throw()->json('sms_balance');

        return $balance === null ? null : (int) $balance;
    }

    private function client(): PendingRequest
    {
        $config = config('integrations.sms');
        if (blank($config['username'] ?? null) || blank($config['password'] ?? null)) {
            throw new RuntimeException('SMS provider is not configured: set SMS_USERNAME and SMS_PASSWORD in .env.');
        }

        return Http::baseUrl(rtrim((string) $config['base_url'], '/'))
            ->withBasicAuth((string) $config['username'], (string) $config['password'])
            ->acceptJson()
            ->asJson()
            ->timeout(15);
    }
}

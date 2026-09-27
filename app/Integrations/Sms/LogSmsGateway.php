<?php

namespace App\Integrations\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Test / local driver: writes messages to the application log instead of sending them.
 * Message bodies are masked because they may carry one-time codes.
 */
class LogSmsGateway implements SmsGateway
{
    /**
     * @var list<array{phone: string, message: string}>
     */
    public array $sent = [];

    public function send(string $phone, string $message): void
    {
        $this->sent[] = ['phone' => $phone, 'message' => $message];

        Log::info('SMS (log driver)', ['phone' => $phone, 'length' => mb_strlen($message)]);
    }
}

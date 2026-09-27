<?php

namespace App\Integrations\Sms;

/**
 * Outbound SMS provider (swappable connector selected by config('integrations.sms.driver')).
 */
interface SmsGateway
{
    /**
     * Send a text message to a phone number in 255XXXXXXXXX format.
     */
    public function send(string $phone, string $message): void;
}

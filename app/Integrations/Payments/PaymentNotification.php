<?php

namespace App\Integrations\Payments;

/**
 * Normalised incoming payment (Documents payload: reference, amount, phone, channel, transaction_id).
 */
final readonly class PaymentNotification
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $transactionId,
        public float $amount,
        public string $channel,
        public ?string $reference = null,
        public ?string $phone = null,
        public ?string $paidOn = null,
        public array $payload = [],
    ) {}
}

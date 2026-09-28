<?php

namespace App\Integrations\Vodacom;

final readonly class DisbursementCallback
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $batchId,
        public bool $success,
        public ?string $transactionId = null,
        public ?string $reason = null,
        public array $payload = [],
    ) {}
}

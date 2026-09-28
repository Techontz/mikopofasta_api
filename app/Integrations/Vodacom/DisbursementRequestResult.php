<?php

namespace App\Integrations\Vodacom;

final readonly class DisbursementRequestResult
{
    /**
     * @param  bool  $accepted  Vodacom accepted the batch (the money has not moved yet).
     * @param  bool|null  $immediateSuccess  Test driver only: simulate the callback outcome right away (null = wait for callback).
     */
    public function __construct(
        public bool $accepted,
        public ?string $providerReference = null,
        public ?string $message = null,
        public ?bool $immediateSuccess = null,
    ) {}
}

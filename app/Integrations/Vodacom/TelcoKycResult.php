<?php

namespace App\Integrations\Vodacom;

final readonly class TelcoKycResult
{
    public function __construct(
        public bool $found,
        public ?string $registeredName = null,
        public ?string $message = null,
    ) {}
}

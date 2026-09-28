<?php

namespace App\Integrations\BankMandate;

final readonly class MandateResult
{
    public function __construct(
        public bool $success,
        public ?string $reference = null,
        public ?string $message = null,
    ) {}
}

<?php

namespace App\Integrations\Linda;

/**
 * Answer of the Linda device service. `confirmed` is true only when Linda confirms the action took effect; `accepted`
 * means it was received and its confirmation will follow.
 */
final readonly class LindaResult
{
    public function __construct(
        public bool $confirmed,
        public bool $accepted = false,
        public ?string $reference = null,
        public ?string $message = null,
        public array $response = [],
    ) {}
}

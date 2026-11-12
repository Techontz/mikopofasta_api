<?php

namespace App\Integrations\Linda;

/**
 * One answer of the Linda Device Lock Partner API. `ok` is a 2xx answer; `status` is the HTTP status (0 when Linda could not
 * be reached or the integration is not configured); `data` is the JSON body. A lock or unlock that is `ok` only means Linda
 * accepted the command - the phone confirms later (webhook or command status).
 */
final readonly class LindaResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public bool $ok,
        public int $status = 0,
        public array $data = [],
        public ?string $message = null,
        public bool $retryable = false,
        public ?int $retryAfter = null,
    ) {}

    /**
     * Network failure, rate limit or a server error: safe to retry later with the same idempotency key.
     */
    public static function unreachable(string $message, int $status = 0, ?int $retryAfter = null): self
    {
        return new self(false, $status, [], $message, true, $retryAfter);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }
}

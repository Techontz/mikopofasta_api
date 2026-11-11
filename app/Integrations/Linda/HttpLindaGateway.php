<?php

namespace App\Integrations\Linda;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Live Linda Device Lock Partner API v1: bearer key, JSON, 15 s timeout. Network failures and 5xx are retried twice at once
 * (lock / unlock always carry the same idempotency key, so a retry never queues a second command); a 429 is returned with
 * its Retry-After for the caller to try later. 4xx answers are never retried unchanged.
 */
class HttpLindaGateway implements LindaGateway
{
    public function register(array $customer, array $device): LindaResult
    {
        return $this->call('post', 'registrations', ['customer' => array_filter($customer, fn ($value) => $value !== null && $value !== ''), 'device' => array_filter($device, fn ($value) => $value !== null && $value !== '')]);
    }

    public function enrollment(string $deviceId): LindaResult
    {
        return $this->call('get', 'devices/'.rawurlencode($deviceId).'/enrollment');
    }

    public function device(string $deviceId): LindaResult
    {
        return $this->call('get', 'devices/'.rawurlencode($deviceId));
    }

    public function lock(string $deviceId, string $reason, string $idempotencyKey): LindaResult
    {
        return $this->call('post', 'devices/'.rawurlencode($deviceId).'/lock', ['reason' => self::reason($reason), 'idempotencyKey' => $idempotencyKey]);
    }

    public function unlock(string $deviceId, string $reason, string $idempotencyKey): LindaResult
    {
        return $this->call('post', 'devices/'.rawurlencode($deviceId).'/unlock', ['reason' => self::reason($reason), 'idempotencyKey' => $idempotencyKey]);
    }

    public function command(string $commandId): LindaResult
    {
        return $this->call('get', 'commands/'.rawurlencode($commandId));
    }

    /**
     * Linda accepts a reason of 3-300 characters.
     */
    public static function reason(string $reason): string
    {
        return mb_substr(str_pad(trim($reason), 3, '.'), 0, 300);
    }

    /**
     * @param  'get'|'post'  $method
     * @param  array<string, mixed>  $body
     */
    private function call(string $method, string $path, array $body = []): LindaResult
    {
        $key = (string) config('integrations.linda.api_key');
        if ($key === '') {
            return new LindaResult(false, 0, [], UnconfiguredLindaGateway::MESSAGE);
        }

        try {
            $response = $this->client($key)->{$method}($path, $body);
        } catch (ConnectionException $exception) {
            return LindaResult::unreachable('Linda could not be reached: '.$exception->getMessage());
        }

        return $this->result($response);
    }

    private function client(string $key): PendingRequest
    {
        return Http::withToken($key)
            ->acceptJson()
            ->asJson()
            ->timeout(max(5, (int) config('integrations.linda.timeout', 15)))
            ->connectTimeout(5)
            ->baseUrl(rtrim((string) config('integrations.linda.base_url'), '/'))
            ->retry([300, 1000], when: fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()), throw: false);
    }

    private function result(Response $response): LindaResult
    {
        $data = is_array($response->json()) ? $response->json() : [];
        if ($response->successful()) {
            return new LindaResult(true, $response->status(), $data);
        }

        $message = $data['message'] ?? $response->reason();
        $message = is_array($message) ? implode('; ', $message) : (string) $message;
        $message = "Linda {$response->status()}: {$message}";

        if ($response->status() === 429 || $response->serverError()) {
            $retryAfter = $response->header('Retry-After');

            return LindaResult::unreachable($message, $response->status(), is_numeric($retryAfter) ? (int) $retryAfter : null);
        }

        return new LindaResult(false, $response->status(), $data, $message);
    }
}

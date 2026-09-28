<?php

namespace App\Http\Middleware;

use App\Models\IdempotentRequest;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Generic `Idempotency-Key` protection for mutating API requests (Fund Flow Specification §30, Rule 19).
 *
 * For POST / PUT / PATCH / DELETE requests of a signed-in employee that carry the header:
 *  - the key is reserved (unique per employee) before the request runs;
 *  - a retry with the same key and the same request replays the stored 2xx response (`Idempotent-Replayed: true`);
 *  - the same key with a different request → 422; the same key while the first request is still running → 409;
 *  - a non-2xx response or an exception releases the key so a corrected retry can proceed.
 * Requests without the header are untouched. Body `idempotency_key` fields handled by services are unaffected.
 */
class EnsureIdempotentRequest
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAYED_HEADER = 'Idempotent-Replayed';

    public const MISMATCH_MESSAGE = 'This request key was already used for a different request.';

    public const IN_FLIGHT_MESSAGE = 'This request is already being processed.';

    /**
     * Request attribute listing top-level response keys that must never be persisted (one-time secrets such as a new
     * shareholder login's temporary password). They are removed from the stored body; a replay answers without them and
     * with `secrets_redacted: true`.
     */
    public const REDACT_ATTRIBUTE = 'idempotency.redact';

    /** A reservation older than this is treated as abandoned (the process died) and may be taken over. */
    private const STALE_AFTER_SECONDS = 600;

    /**
     * Mark top-level response keys of the current request as one-time secrets that must not be stored for replays.
     * Set on the application's request instance (a FormRequest is a copy with its own attribute bag).
     *
     * @param  list<string>  $keys
     */
    public static function doNotStore(array $keys): void
    {
        app('request')->attributes->set(self::REDACT_ATTRIBUTE, $keys);
    }

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get(self::HEADER);
        // Password changes are never recorded: the stored request hash would be derived from the passwords.
        if ($key === null || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true) || $request->routeIs('api.v1.auth.password')) {
            return $next($request);
        }

        if (preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key) !== 1) {
            $message = 'The Idempotency-Key header must be 8 to 100 characters of letters, digits, ".", "_", ":" or "-".';

            return new JsonResponse(['message' => $message, 'errors' => ['idempotency_key' => [$message]]], 422);
        }

        $employee = $request->user('sanctum') ?? $request->user();
        if ($employee === null) {
            return $next($request);
        }

        $hash = $this->hash($request);
        $record = $this->reserve($request, (int) $employee->getKey(), $employee->company_id ?? null, $key, $hash);

        if (! $record instanceof IdempotentRequest) {
            return $record;
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $record->delete();

            throw $exception;
        }

        $this->complete($record, $response, (array) $request->attributes->get(self::REDACT_ATTRIBUTE, []));

        return $response;
    }

    /**
     * Reserve the key, or answer from an existing reservation (replay / 409 / 422).
     */
    private function reserve(Request $request, int $employeeId, ?int $companyId, string $key, string $hash, bool $retried = false): IdempotentRequest|Response
    {
        try {
            return IdempotentRequest::create([
                'company_id' => $companyId,
                'employee_id' => $employeeId,
                'key' => $key,
                'method' => $request->method(),
                'route' => substr($request->route()?->getName() ?? $request->path(), 0, 255),
                'request_hash' => $hash,
                'status' => IdempotentRequest::STATUS_PROCESSING,
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = IdempotentRequest::query()->where('employee_id', $employeeId)->where('key', $key)->first();

            if ($existing === null) {
                return $retried ? $this->error(self::IN_FLIGHT_MESSAGE, 409) : $this->reserve($request, $employeeId, $companyId, $key, $hash, true);
            }

            if (! hash_equals($existing->request_hash, $hash)) {
                return $this->error(self::MISMATCH_MESSAGE, 422);
            }

            if ($existing->status === IdempotentRequest::STATUS_COMPLETED) {
                return $this->replay($existing);
            }

            if ($existing->updated_at !== null && $existing->updated_at->lt(now()->subSeconds(self::STALE_AFTER_SECONDS))) {
                $takenOver = IdempotentRequest::query()->whereKey($existing->id)
                    ->where('status', IdempotentRequest::STATUS_PROCESSING)
                    ->where('updated_at', $existing->updated_at)
                    ->update(['updated_at' => now()]);

                if ($takenOver === 1) {
                    return $existing->refresh();
                }
            }

            return $this->error(self::IN_FLIGHT_MESSAGE, 409);
        }
    }

    /**
     * Keep a successful response for replays; release the key otherwise.
     */
    /**
     * @param  list<string>  $redact
     */
    private function complete(IdempotentRequest $record, Response $response, array $redact = []): void
    {
        $status = $response->getStatusCode();
        $storable = $status >= 200 && $status < 300 && ! $response instanceof StreamedResponse && ! $response instanceof BinaryFileResponse;

        if (! $storable) {
            $record->delete();

            return;
        }

        $record->update([
            'status' => IdempotentRequest::STATUS_COMPLETED,
            'response_status' => $status,
            'response_body' => $this->redact((string) $response->getContent(), $redact),
        ]);
    }

    /**
     * Remove one-time secrets from a JSON body before it is stored.
     *
     * @param  list<string>  $keys
     */
    private function redact(string $body, array $keys): string
    {
        if ($keys === []) {
            return $body;
        }

        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            return (string) json_encode(['message' => 'Completed', 'secrets_redacted' => true]);
        }

        foreach ($keys as $key) {
            unset($payload[$key]);
            if (isset($payload['data']) && is_array($payload['data'])) {
                unset($payload['data'][$key]);
            }
        }

        return (string) json_encode($payload + ['secrets_redacted' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function replay(IdempotentRequest $record): Response
    {
        return response((string) $record->response_body, (int) $record->response_status, [
            'Content-Type' => 'application/json',
            self::REPLAYED_HEADER => 'true',
        ]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'errors' => ['idempotency_key' => [$message]]], $status);
    }

    /**
     * sha256 of method, path and the canonical request payload (uploaded files by name and size).
     */
    private function hash(Request $request): string
    {
        $payload = [
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'query' => $this->canonical($request->query()),
            'body' => $this->canonical($request->isJson() ? $request->json()->all() : $request->request->all()),
            'files' => $this->canonical($request->allFiles()),
        ];

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonical(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return ['file' => $value->getClientOriginalName(), 'size' => $value->getSize()];
        }

        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
        }

        return $value;
    }
}

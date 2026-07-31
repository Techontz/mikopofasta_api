<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec §1: "Every endpoint that triggers external side effects or money
 * movement requires an `Idempotency-Key` header; the server stores a hash of
 * (key + endpoint) for 24h and replays the original response on duplicate
 * submission."
 *
 * This is that middleware. It makes retrying a money-moving request safe —
 * which matters most for the requests nobody chose to retry: a provider
 * resending a callback it never saw acknowledged, a teller's phone losing
 * signal mid-POST and the app trying again.
 *
 * ## How a duplicate is recognised
 *
 * The cache key is a hash of the idempotency key, the route and the request
 * body. Including the ROUTE means the same key used on two different endpoints
 * is two different operations, as §1 specifies. Including the BODY is what
 * turns a reused key carrying different data into a 409 rather than a silent
 * replay of the wrong response — a client that reuses a key for a different
 * payment has a bug, and hiding it would post one payment and acknowledge
 * another.
 *
 * ## The lock
 *
 * A reservation is taken before the request runs, so two simultaneous copies
 * cannot both execute — the second waits and then replays the first's
 * response. Without it, two callbacks arriving in the same millisecond would
 * both pass the "have I seen this?" check and both post to the ledger.
 *
 * ## What is stored
 *
 * Only successful responses (2xx) are remembered. A failure must be retryable:
 * replaying a 500 for 24 hours would make a transient database blip permanent.
 */
final class EnsureIdempotency
{
    public const string HEADER = 'Idempotency-Key';

    /** §1's replay window. */
    private const int TTL_SECONDS = 86_400;

    /** How long a request may hold the reservation before it is presumed dead. */
    private const int LOCK_SECONDS = 30;

    /** How long a concurrent duplicate waits for the first to finish. */
    private const int LOCK_WAIT_SECONDS = 10;

    /**
     * @param Closure(Request): Response $next
     * @param string $requirement `required` to refuse an unkeyed request,
     *                            anything else to let one through unguarded.
     */
    public function handle(Request $request, Closure $next, string $requirement = 'optional'): Response
    {
        $key = trim((string) $request->header(self::HEADER, ''));

        if ($key === '') {
            /*
             * A provider that does not send the header still has to work — its
             * callbacks are protected by the UNIQUE transaction_id and the
             * per-record status markers instead. Endpoints under our own
             * clients' control declare `required` and refuse.
             */
            return $requirement === 'required'
                ? ApiResponse::error(
                    sprintf('This endpoint requires an %s header.', self::HEADER),
                    ErrorCode::IdempotencyKeyRequired,
                    Response::HTTP_BAD_REQUEST,
                )
                : $next($request);
        }

        $cache = Cache::store(config('cache.default'));
        $fingerprint = $this->fingerprint($request, $key);
        $cacheKey = 'idempotency:'.$fingerprint;

        if (($replay = $this->replay($cache, $cacheKey, $request, $key)) !== null) {
            return $replay;
        }

        $store = $cache->getStore();

        /*
         * The reservation needs an atomic lock, which only a LockProvider
         * store offers — Redis, the configured production store, is one. A
         * store without locking still gets replay protection, just not the
         * guarantee against two simultaneous first-attempts; the difference is
         * stated rather than hidden.
         */
        $lock = $store instanceof LockProvider
            ? $store->lock('idempotency-lock:'.$fingerprint, self::LOCK_SECONDS)
            : null;

        if ($lock !== null && ! $lock->block(self::LOCK_WAIT_SECONDS, static fn (): bool => true)) {
            // The holder is still running after the wait. Better a 409 the
            // client retries than a second execution.
            return $this->conflict('A request with this Idempotency-Key is still in flight.');
        }

        try {
            /*
             * Re-read inside the lock. The request that held it may have
             * finished and stored its response while we were blocked — a
             * different PROCESS, so this is not the same read as the one
             * above however identical it looks.
             */
            $stored = $cache->get($cacheKey);

            if (is_array($stored)) {
                return $this->replayResponse($stored, $request, $key);
            }

            $response = $next($request);

            if ($response->getStatusCode() < 300) {
                $cache->put($cacheKey, [
                    'status' => $response->getStatusCode(),
                    'body' => $response->getContent(),
                    'route' => $this->route($request),
                ], self::TTL_SECONDS);
            }

            return $response;
        } finally {
            $lock?->release();
        }
    }

    /**
     * The stored response for this exact (key + route + body), if there is one.
     */
    private function replay(CacheRepository $cache, string $cacheKey, Request $request, string $key): ?Response
    {
        $stored = $cache->get($cacheKey);

        if (! is_array($stored)) {
            return $this->conflictIfKeyReused($cache, $request, $key);
        }

        return $this->replayResponse($stored, $request, $key);
    }

    /**
     * Rebuilds the original response from what was stored.
     *
     * `Idempotent-Replay: true` tells a client that nothing ran this time —
     * without it a caller cannot distinguish a replay from a fresh success,
     * and would have no way to notice it had accidentally retried.
     *
     * @param array<string, mixed> $stored
     */
    private function replayResponse(array $stored, Request $request, string $key): Response
    {
        Log::channel('operations')->info('Idempotent replay', [
            'route' => $this->route($request),
            'status' => $stored['status'],
            // The key itself is a client secret of sorts; only its hash is logged.
            'key_hash' => substr(hash('sha256', $key), 0, 16),
        ]);

        return response(
            $stored['body'],
            (int) $stored['status'],
            [
                'Content-Type' => 'application/json',
                'Idempotent-Replay' => 'true',
            ],
        );
    }

    /**
     * The same key on the same route with a DIFFERENT body.
     *
     * Recognised by storing the key→route pair separately from the full
     * fingerprint: if the pair is known but the fingerprint is not, the client
     * has reused a key for different data.
     */
    private function conflictIfKeyReused(CacheRepository $cache, Request $request, string $key): ?Response
    {
        $pairKey = 'idempotency-pair:'.hash('sha256', $key.'|'.$this->route($request));

        if ($cache->get($pairKey) !== null) {
            Log::channel('operations')->warning('Idempotency key reused with a different payload', [
                'route' => $this->route($request),
                'key_hash' => substr(hash('sha256', $key), 0, 16),
            ]);

            return $this->conflict(
                'This Idempotency-Key has already been used for a different request.',
            );
        }

        $cache->put($pairKey, true, self::TTL_SECONDS);

        return null;
    }

    /**
     * (key + route + body), per §1's "hash of (key + endpoint)" plus the body,
     * so a reused key carrying different data is detectable.
     */
    private function fingerprint(Request $request, string $key): string
    {
        return hash('sha256', implode('|', [$key, $this->route($request), $request->getContent()]));
    }

    private function route(Request $request): string
    {
        return $request->method().' '.($request->route()?->uri() ?? $request->path());
    }

    private function conflict(string $message): Response
    {
        return ApiResponse::error($message, ErrorCode::IdempotencyKeyConflict, Response::HTTP_CONFLICT);
    }
}

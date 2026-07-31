<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies a provider's HMAC signature before anything else runs — spec §1:
 * "verified via a provider-specific HMAC signature header checked against a
 * shared secret in config — never trust an unsigned callback".
 *
 * Registered as route middleware on the webhook routes, so it executes before
 * the controller, before the Form Request, and before a single row is written.
 * That ordering is the whole point: `POST /webhooks/payments` posts to the
 * ledger and the disbursement callback activates a loan, so an unverified
 * caller must not reach either.
 *
 * ## What is signed
 *
 * The RAW request body, exactly as received. Re-encoding the parsed payload
 * would change key order and whitespace and would therefore fail against a
 * signature the provider computed over their own bytes. When the provider also
 * sends a timestamp header, the signed string is `{timestamp}.{body}` — the
 * scheme Stripe popularised and most providers copy — which is what makes a
 * captured callback unreplayable after the tolerance window.
 *
 * ## Failure is always 401
 *
 * Missing, malformed, mismatched and expired all return 401 with the same
 * body. Distinguishing them would tell an attacker which half of the check
 * they had passed.
 */
final class VerifyWebhookSignature
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next, string $provider): Response
    {
        $config = config("webhooks.providers.{$provider}");

        if (! is_array($config)) {
            return $this->reject($request, $provider, 'unknown_provider');
        }

        $secret = (string) ($config['secret'] ?? '');

        /*
         * An unconfigured secret rejects rather than waves through. A
         * deployment that forgot to set it should fail loudly and closed —
         * treating "no secret" as "no verification needed" is precisely how an
         * endpoint that posts to the ledger ends up open to the internet.
         */
        if ($secret === '') {
            return $this->reject($request, $provider, 'secret_not_configured');
        }

        $provided = (string) $request->header((string) $config['header'], '');

        if ($provided === '') {
            return $this->reject($request, $provider, 'signature_missing');
        }

        $timestamp = (string) $request->header((string) config('webhooks.timestamp_header'), '');

        if (! $this->timestampWithinTolerance($timestamp)) {
            return $this->reject($request, $provider, 'timestamp_outside_tolerance');
        }

        $expected = hash_hmac(
            (string) ($config['algorithm'] ?? 'sha256'),
            $this->signedPayload($request, $timestamp),
            $secret,
        );

        /*
         * Constant-time. A plain === leaks, through timing, how many leading
         * characters of a guess were right, which is enough to recover a
         * signature byte by byte.
         */
        if (! hash_equals($expected, $this->normalise($provided))) {
            return $this->reject($request, $provider, 'signature_mismatch');
        }

        Log::channel('operations')->info('Webhook signature verified', [
            'provider' => $provider,
            'path' => $request->path(),
            'ip' => $request->ip(),
        ]);

        return $next($request);
    }

    /**
     * The exact bytes the signature covers.
     */
    private function signedPayload(Request $request, string $timestamp): string
    {
        $body = $request->getContent();

        return $timestamp === '' ? $body : $timestamp.'.'.$body;
    }

    /**
     * Providers prefix the hex digest in various ways (`sha256=…`, `v1=…`).
     * The digest itself is the last `=`-delimited segment.
     */
    private function normalise(string $signature): string
    {
        $trimmed = trim($signature);

        if (! str_contains($trimmed, '=')) {
            return $trimmed;
        }

        $parts = explode('=', $trimmed);

        return (string) end($parts);
    }

    /**
     * A provider that sends no timestamp is accepted — the check cannot apply
     * — and idempotency remains the replay defence there.
     */
    private function timestampWithinTolerance(string $timestamp): bool
    {
        $tolerance = (int) config('webhooks.tolerance_seconds');

        if ($tolerance <= 0 || $timestamp === '') {
            return true;
        }

        if (! ctype_digit($timestamp)) {
            return false;
        }

        return abs(time() - (int) $timestamp) <= $tolerance;
    }

    private function reject(Request $request, string $provider, string $reason): Response
    {
        /*
         * The reason is logged, never returned. An operator needs to know
         * whether a secret is missing or a signature is wrong; a caller must
         * not learn which.
         *
         * Nothing here logs the signature, the secret or the body.
         */
        Log::channel('operations')->warning('Webhook rejected', [
            'provider' => $provider,
            'reason' => $reason,
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return ApiResponse::error(
            'The webhook signature could not be verified.',
            ErrorCode::InvalidWebhookSignature,
            Response::HTTP_UNAUTHORIZED,
        );
    }
}

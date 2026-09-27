<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Error envelope for every api/* JSON error: { message, error_code, errors? } (CUSTOMER_MODULE_IMPLEMENTATION.md §3).
 * Applied to rendered exceptions (bootstrap/app.php) and to JSON error responses returned by controllers.
 */
class ApiErrorEnvelope
{
    /**
     * @var array<int, string>
     */
    public const CODES = [
        400 => 'BAD_REQUEST',
        401 => 'UNAUTHENTICATED',
        403 => 'FORBIDDEN',
        404 => 'RESOURCE_NOT_FOUND',
        405 => 'METHOD_NOT_ALLOWED',
        409 => 'CONFLICT',
        413 => 'PAYLOAD_TOO_LARGE',
        419 => 'SESSION_EXPIRED',
        422 => 'VALIDATION_FAILED',
        429 => 'TOO_MANY_REQUESTS',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($next($request));
    }

    public static function codeFor(int $status): string
    {
        return self::CODES[$status] ?? ($status >= 500 ? 'SERVER_ERROR' : 'HTTP_ERROR');
    }

    /**
     * Add `error_code` (and a message) to a JSON error response that lacks one.
     */
    public static function apply(Response $response): Response
    {
        if (! $response instanceof JsonResponse || $response->getStatusCode() < 400) {
            return $response;
        }

        $payload = $response->getData(true);
        if (! is_array($payload) || isset($payload['error_code'])) {
            return $response;
        }

        $status = $response->getStatusCode();

        return $response->setData(['message' => $payload['message'] ?? (Response::$statusTexts[$status] ?? 'Error'), 'error_code' => self::codeFor($status)] + $payload);
    }
}

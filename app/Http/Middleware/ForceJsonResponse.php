<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * This service is API-only. Forcing the `Accept` header guarantees Laravel
 * negotiates JSON for every response — including validation failures and
 * uncaught exceptions — so a client that forgets the header still gets the
 * documented envelope rather than an HTML error page.
 */
final class ForceJsonResponse
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}

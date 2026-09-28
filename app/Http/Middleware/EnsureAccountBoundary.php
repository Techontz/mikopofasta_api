<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Account boundaries for signed-in API requests (applied to the auth:sanctum route group):
 *
 *  - an account that must change its (temporary) password reaches only auth/me, auth/logout and auth/password
 *    → 403 `PASSWORD_CHANGE_REQUIRED` everywhere else;
 *  - a shareholder portal login (employees.account_type = shareholder) reaches only the auth routes and the Shareholder
 *    Portal API (portal/shareholder/*) → 403 `SHAREHOLDER_ACCOUNT` on every staff endpoint, whatever its permission checks.
 *
 * Staff accounts linked to a shareholder keep their staff access (the permission checks of each endpoint apply).
 */
class EnsureAccountBoundary
{
    /**
     * Route names every signed-in account may call.
     *
     * @var list<string>
     */
    public const AUTH_ROUTES = ['api.v1.auth.me', 'api.v1.auth.logout', 'api.v1.auth.password'];

    public const PORTAL_ROUTE_PREFIX = 'api.v1.portal.shareholder.';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $employee = $request->user();
        if (! $employee instanceof Employee) {
            return $next($request);
        }

        $route = (string) $request->route()?->getName();
        if (in_array($route, self::AUTH_ROUTES, true)) {
            return $next($request);
        }

        if ($employee->must_change_password) {
            return new JsonResponse([
                'message' => 'Change your temporary password before continuing.',
                'error_code' => 'PASSWORD_CHANGE_REQUIRED',
            ], 403);
        }

        if ($employee->isShareholderAccount() && ! str_starts_with($route, self::PORTAL_ROUTE_PREFIX)) {
            return new JsonResponse([
                'message' => 'Shareholder accounts can only use the Shareholder Portal.',
                'error_code' => 'SHAREHOLDER_ACCOUNT',
            ], 403);
        }

        return $next($request);
    }
}

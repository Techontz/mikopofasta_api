<?php

declare(strict_types=1);

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            /*
             * Every business endpoint lives under /api/v1 (backend spec §1) —
             * versioned from day one so a breaking change can ship as /api/v2
             * without disturbing existing consumers.
             */
            Route::middleware('api')
                ->prefix('api/v1')
                ->name('api.v1.')
                ->group(base_path('routes/api.php'));

            /*
             * Provider callbacks are deliberately unversioned and sit outside
             * the Sanctum-protected group: they authenticate with a
             * per-provider HMAC signature, not a bearer token (spec §1).
             */
            Route::middleware('api')
                ->prefix('webhooks')
                ->name('webhooks.')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        /*
         * Applies the `api` rate limiter defined in AppServiceProvider to
         * every API route. Endpoints that need a tighter budget (login,
         * password reset) layer their own `throttle:` middleware on top.
         */
        $middleware->throttleApi('api');

        $middleware->alias([
            /*
             * §1's two platform guards. Both are route middleware rather than
             * global: `webhook.signature` must name its provider, and
             * `idempotency` applies only to endpoints that move money.
             */
            'webhook.signature' => App\Http\Middleware\VerifyWebhookSignature::class,
            'idempotency' => App\Http\Middleware\EnsureIdempotency::class,

            'role' => Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Every API error leaves the application in the spec §1 envelope
         * shape — { message, error_code, errors? } — rendered in one place.
         */
        $exceptions->render(new ApiExceptionRenderer);

        /*
         * Credentials must never reach the log, even when a request throws
         * mid-authentication.
         */
        $exceptions->dontFlash([
            'password',
            'password_confirmation',
            'current_password',
            'token',
        ]);
    })->create();

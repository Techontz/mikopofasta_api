<?php

use App\Http\Middleware\ApiErrorEnvelope;
use App\Http\Middleware\EnsureAccountBoundary;
use App\Http\Middleware\EnsureCompanyOwnership;
use App\Http\Middleware\EnsureIdempotentRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')->prefix('api')->group(__DIR__.'/../routes/webhooks.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(function (Request $request): ?string {
            return $request->is('api/*') ? null : route('login');
        });

        $middleware->prependToGroup('api', ApiErrorEnvelope::class);

        $middleware->prependToGroup('api', ApiErrorEnvelope::class);
        $middleware->appendToGroup('api', EnsureCompanyOwnership::class);
        $middleware->appendToGroup('api', EnsureIdempotentRequest::class);
        // Shareholder / forced-password-change boundary runs right after authentication, before route model binding.
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureAccountBoundary::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Global API error envelope: { message, error_code, errors? }.
        $exceptions->respond(
            fn (Response $response, Throwable $exception, Request $request): Response => $request->is('api/*') ? ApiErrorEnvelope::apply($response) : $response,
        );
    })->create();

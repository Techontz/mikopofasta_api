<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Middleware\EnsureAccountBoundary;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — consumed by the Next.js frontend (web/)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');
    Route::get('auth/login-stats', [AuthController::class, 'loginStats'])->middleware('throttle:60,1')->name('auth.login-stats');

    Route::middleware(['auth:sanctum', EnsureAccountBoundary::class])->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/password', [AuthController::class, 'changePassword'])->name('auth.password');

        foreach (glob(__DIR__.'/api/*.php') as $moduleRoutes) {
            require $moduleRoutes;
        }
    });
});

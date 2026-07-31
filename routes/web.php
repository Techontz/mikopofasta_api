<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| This service is API-only — it renders no views. The root route exists so
| that hitting the host in a browser returns a useful pointer rather than a
| 404. Liveness/readiness probing is handled separately by /up.
|
*/

Route::get('/', function (): JsonResponse {
    return response()->json([
        'data' => [
            'service' => config('app.name'),
            'api_version' => 'v1',
            'documentation' => 'See README.md',
            'endpoints' => [
                'api' => url('/api/v1'),
                'health' => url('/api/v1/health'),
                'liveness' => url('/up'),
            ],
        ],
    ]);
})->name('root');

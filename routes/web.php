<?php

use Illuminate\Support\Facades\Route;

/**
 * The user interface is the Next.js app in ../web; Laravel only serves the API (/api/v1) and webhooks.
 */
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api/v1'),
]))->name('home');

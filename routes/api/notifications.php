<?php

use App\Http\Controllers\Api\V1\Notifications\NotificationController;
use Illuminate\Support\Facades\Route;

Route::controller(NotificationController::class)->prefix('notifications')->name('notifications.')->group(function (): void {
    Route::get('/', 'index')->name('index');
    Route::post('read-all', 'readAll')->name('read-all');
    Route::post('{notification}/read', 'read')->whereUuid('notification')->name('read');
});

<?php

use App\Http\Controllers\Api\V1\Reversals\ReversalRequestController;
use Illuminate\Support\Facades\Route;

Route::controller(ReversalRequestController::class)->prefix('reversal-requests')->name('reversal-requests.')->group(function (): void {
    Route::get('/', 'index')->name('index');
    Route::post('{reversalRequest}/approve', 'approve')->whereNumber('reversalRequest')->name('approve');
    Route::post('{reversalRequest}/reject', 'reject')->whereNumber('reversalRequest')->name('reject');
});

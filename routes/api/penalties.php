<?php

use App\Http\Controllers\Api\V1\Penalties\PenaltyController;
use Illuminate\Support\Facades\Route;

Route::controller(PenaltyController::class)->prefix('penalties')->name('penalties.')->group(function (): void {
    Route::get('/', 'index')->name('index');
    Route::get('paid', 'paid')->name('paid');
    Route::post('{penalty}/pay', 'pay')->name('pay');
    Route::post('{penalty}/waive', 'waive')->name('waive');
    Route::post('payments/{penaltyPayment}/reverse', 'reversePayment')->whereNumber('penaltyPayment')->name('payments.reverse');
});

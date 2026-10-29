<?php

use App\Http\Controllers\Api\V1\Loans\PhoneBondController;
use Illuminate\Support\Facades\Route;

// Phone Bond — Linda (enhancement §5).
Route::prefix('phone-bond')->name('phone-bond.')->controller(PhoneBondController::class)->group(function (): void {
    Route::get('devices', 'index')->name('devices.index');
    Route::post('devices', 'store')->name('devices.store');
    Route::get('devices/{device}', 'show')->whereNumber('device')->name('devices.show');
    Route::post('devices/{device}/close', 'close')->whereNumber('device')->name('devices.close');
    Route::post('devices/{device}/{action}', 'action')->whereNumber('device')->whereIn('action', ['lock', 'unlock'])->name('devices.action');
    Route::post('requests/{lockRequest}/confirm', 'confirm')->whereNumber('lockRequest')->name('requests.confirm');
    Route::get('report', 'report')->name('report');
});

<?php

use App\Http\Controllers\Api\V1\Loans\CompanyDeviceController;
use Illuminate\Support\Facades\Route;

// eSIMU → Company Devices: company-owned smartphones under Linda Device Lock (no loans).
Route::prefix('company-devices')->name('company-devices.')->controller(CompanyDeviceController::class)->group(function (): void {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::get('{device}', 'show')->whereNumber('device')->name('show');
    Route::post('{device}/register', 'retryRegistration')->whereNumber('device')->name('register');
    Route::post('{device}/enrollment', 'checkEnrollment')->whereNumber('device')->name('enrollment');
    Route::post('{device}/incidents', 'reportIncident')->whereNumber('device')->name('incidents');
    Route::post('{device}/{action}', 'action')->whereNumber('device')->whereIn('action', ['lock', 'unlock'])->name('action');
});

<?php

use App\Http\Controllers\Api\V1\Loans\PhoneBondController;
use Illuminate\Support\Facades\Route;

// eSIMU (the Phone Bond loan method) - Linda Device Lock.
Route::prefix('phone-bond')->name('phone-bond.')->controller(PhoneBondController::class)->group(function (): void {
    Route::get('devices', 'index')->name('devices.index');
    Route::get('devices/{device}', 'show')->whereNumber('device')->name('devices.show');
    Route::post('registrations', 'register')->name('registrations.store');
    Route::get('customers/{customer}/devices', 'customerDevices')->whereNumber('customer')->name('customers.devices');
    Route::post('devices/{device}/register', 'retryRegistration')->whereNumber('device')->name('devices.register');
    Route::post('devices/{device}/enrollment', 'checkEnrollment')->whereNumber('device')->name('devices.enrollment');
    Route::post('devices/{device}/inspection', 'inspect')->whereNumber('device')->name('devices.inspection');
    Route::post('devices/{device}/release', 'release')->whereNumber('device')->name('devices.release');
    Route::post('devices/{device}/incidents', 'reportIncident')->whereNumber('device')->name('devices.incidents');
    Route::post('devices/{device}/replacements', 'requestReplacement')->whereNumber('device')->name('devices.replacements');
    Route::post('devices/{device}/{action}', 'action')->whereNumber('device')->whereIn('action', ['lock', 'unlock'])->name('devices.action');
    Route::post('requests/{lockRequest}/confirm', 'confirm')->whereNumber('lockRequest')->name('requests.confirm');
    Route::get('replacements', 'replacements')->name('replacements.index');
    Route::post('replacements/{replacement}/{decision}', 'decideReplacement')->whereNumber('replacement')->whereIn('decision', ['approve', 'reject'])->name('replacements.decide');
    Route::get('report', 'report')->name('report');
    Route::get('payments', 'payments')->name('payments');
});

<?php

use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\OptionController;
use Illuminate\Support\Facades\Route;

Route::get('dashboard', DashboardController::class)->name('dashboard');

Route::controller(OptionController::class)->prefix('options')->name('options.')->group(function (): void {
    Route::get('branches', 'branchOptions')->name('branches');
    Route::get('regions', 'regions')->name('regions');
    Route::get('employees', 'employees')->name('employees');
    Route::get('customers', 'customers')->name('customers');
});

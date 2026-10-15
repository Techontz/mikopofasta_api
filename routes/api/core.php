<?php

use App\Http\Controllers\Api\V1\BranchDashboardController;
use App\Http\Controllers\Api\V1\CreditDashboardController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FinanceDashboardController;
use App\Http\Controllers\Api\V1\HrDashboardController;
use App\Http\Controllers\Api\V1\OptionController;
use App\Http\Controllers\Api\V1\ZoneDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('dashboard', DashboardController::class)->name('dashboard');
Route::get('dashboard/finance', FinanceDashboardController::class)->name('dashboard.finance');
Route::get('dashboard/credit', CreditDashboardController::class)->name('dashboard.credit');
Route::get('dashboard/zone', ZoneDashboardController::class)->name('dashboard.zone');
Route::get('dashboard/hr', HrDashboardController::class)->name('dashboard.hr');
Route::get('dashboard/branch', BranchDashboardController::class)->name('dashboard.branch');

Route::controller(OptionController::class)->prefix('options')->name('options.')->group(function (): void {
    Route::get('branches', 'branchOptions')->name('branches');
    Route::get('regions', 'regions')->name('regions');
    Route::get('employees', 'employees')->name('employees');
    Route::get('customers', 'customers')->name('customers');
});

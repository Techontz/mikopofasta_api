<?php

use App\Http\Controllers\Api\V1\Settings\BranchController;
use App\Http\Controllers\Api\V1\Settings\CompanySettingController;
use App\Http\Controllers\Api\V1\Settings\FormulaController;
use App\Http\Controllers\Api\V1\Settings\LoanCategoryController;
use App\Http\Controllers\Api\V1\Settings\LoanFeeController;
use App\Http\Controllers\Api\V1\Settings\PaymentProviderController;
use App\Http\Controllers\Api\V1\Settings\RoleController;
use App\Http\Controllers\Api\V1\Settings\SettingsOptionController;
use App\Http\Controllers\Api\V1\Settings\ZoneController;
use Illuminate\Support\Facades\Route;

Route::prefix('settings')->name('settings.')->group(function (): void {
    Route::apiResource('branches', BranchController::class);
    Route::apiResource('zones', ZoneController::class);
    Route::get('payment-providers/options', [PaymentProviderController::class, 'options'])->name('payment-providers.options');
    Route::apiResource('payment-providers', PaymentProviderController::class)->except('show');

    Route::controller(FormulaController::class)->group(function (): void {
        Route::get('formulas', 'index')->name('formulas.index');
        Route::post('formulas/{formula}/enable', 'enable')->name('formulas.enable');
        Route::delete('formulas/{formula}', 'disable')->name('formulas.disable');
    });

    Route::apiResource('loan-categories', LoanCategoryController::class);
    Route::post('loan-categories/{loanCategory}/branches/{branch}', [LoanCategoryController::class, 'attachBranch'])->name('loan-categories.attach-branch');
    Route::delete('loan-categories/{loanCategory}/branches/{branch}', [LoanCategoryController::class, 'detachBranch'])->name('loan-categories.detach-branch');

    Route::controller(LoanFeeController::class)->group(function (): void {
        Route::get('loan-fees', 'index')->name('loan-fees.index');
        Route::put('loan-fees/mode', 'updateMode')->name('loan-fees.mode');
        Route::put('loan-fees/{loanCategory}', 'updateCategory')->name('loan-fees.update');
    });

    Route::controller(CompanySettingController::class)->group(function (): void {
        Route::get('company', 'company')->name('company.show');
        Route::put('company', 'updateCompany')->name('company.update');
        Route::post('company/logo', 'logo')->name('company.logo');
        Route::put('company/password', 'password')->name('company.password');
        Route::get('penalty', 'penalty')->name('penalty.show');
        Route::put('penalty', 'updatePenalty')->name('penalty.update');
        Route::get('reserve', 'reserve')->name('reserve.show');
        Route::put('reserve', 'updateReserve')->name('reserve.update');
        Route::get('dividend', 'dividend')->name('dividend.show');
        Route::put('dividend', 'updateDividend')->name('dividend.update');
        Route::get('loan-freeze', 'loanFreeze')->name('loan-freeze.show');
        Route::put('loan-freeze', 'updateLoanFreeze')->name('loan-freeze.update');
    });

    Route::controller(RoleController::class)->group(function (): void {
        Route::get('roles', 'index')->name('roles.index');
        Route::get('permissions', 'permissions')->name('permissions.index');
        Route::put('roles/{role}/permissions', 'updatePermissions')->name('roles.permissions');
        Route::put('employees/{employee}/role', 'assignEmployeeRole')->name('employees.role');
    });

    Route::controller(SettingsOptionController::class)->prefix('options')->name('options.')->group(function (): void {
        Route::get('formulas', 'formulas')->name('formulas');
        Route::get('durations', 'durations')->name('durations');
        Route::get('approve-levels', 'approveLevels')->name('approve-levels');
        Route::get('loan-categories', 'loanCategories')->name('loan-categories');
        Route::get('customer-categories', 'customerCategories')->name('customer-categories');
        Route::get('zones', 'zones')->name('zones');
        Route::get('roles', 'roles')->name('roles');
    });
});

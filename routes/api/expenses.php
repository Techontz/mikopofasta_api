<?php

use App\Http\Controllers\Api\V1\Expenses\ExpenseRequestController;
use App\Http\Controllers\Api\V1\Expenses\ExpenseTypeController;
use App\Http\Controllers\Api\V1\Expenses\LoanFeeIncomeController;
use Illuminate\Support\Facades\Route;

Route::prefix('expenses')->name('expenses.')->group(function (): void {
    Route::get('options/types', [ExpenseTypeController::class, 'options'])->name('options.types');
    Route::apiResource('types', ExpenseTypeController::class)->except('show')->parameters(['types' => 'expenseType']);

    Route::controller(ExpenseRequestController::class)->group(function (): void {
        Route::get('settings', 'settings')->name('settings.show');
        Route::put('settings', 'updateSettings')->name('settings.update');
        Route::get('requests', 'index')->name('requests.index');
        Route::post('requests', 'store')->name('requests.store');
        Route::post('requests/{expenseRequest}/accept', 'accept')->name('requests.accept');
        Route::delete('requests/{expenseRequest}', 'destroy')->name('requests.destroy');
        Route::post('requests/{expenseRequest}/reverse', 'reverse')->name('requests.reverse');
    });
});

Route::get('loan-fees/income', LoanFeeIncomeController::class)->name('loan-fees.income');

<?php

use App\Http\Controllers\Api\V1\SalaryAdvance\SalaryAdvanceCategoryController;
use App\Http\Controllers\Api\V1\SalaryAdvance\SalaryAdvanceController;
use Illuminate\Support\Facades\Route;

Route::prefix('salary-advance')->name('salary-advance.')->group(function (): void {
    Route::apiResource('categories', SalaryAdvanceCategoryController::class)
        ->except('show')
        ->parameters(['categories' => 'salaryAdvanceCategory']);

    Route::controller(SalaryAdvanceController::class)->group(function (): void {
        Route::get('requested', 'requested')->name('requested');
        Route::post('advances', 'store')->name('advances.store');
        Route::post('advances/{salaryAdvance}/approve', 'approve')->name('advances.approve');
        Route::post('advances/{salaryAdvance}/payments', 'pay')->name('advances.pay');
        Route::post('payments/{salaryAdvancePayment}/reverse', 'reversePayment')->name('payments.reverse');
        Route::post('advances/{salaryAdvance}/collect-fee', 'collectFee')->name('advances.collect-fee');
        Route::delete('advances/{salaryAdvance}', 'destroy')->name('advances.destroy');
        Route::get('approved', 'approved')->name('approved');
        Route::get('active', 'active')->name('active');
        Route::get('repayments', 'repayments')->name('repayments');
        Route::get('paid', 'paid')->name('paid');
    });
});

<?php

use App\Http\Controllers\Api\V1\Hq\HqTransactionController;
use Illuminate\Support\Facades\Route;

Route::prefix('hq')->name('hq.')->controller(HqTransactionController::class)->group(function (): void {
    Route::get('balances', 'balances')->name('balances');
    Route::get('options/accounts', 'accountOptions')->name('options.accounts');
    Route::get('transactions', 'index')->name('transactions.index');
    Route::post('transactions', 'store')->name('transactions.store');
    Route::post('transactions/{hqTransaction}/approve', 'approve')->name('transactions.approve');
    Route::delete('transactions/{hqTransaction}', 'destroy')->name('transactions.destroy');
    Route::post('transactions/{hqTransaction}/reverse', 'reverse')->name('transactions.reverse');
});

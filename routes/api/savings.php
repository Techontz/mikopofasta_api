<?php

use App\Http\Controllers\Api\V1\Savings\SavingController;
use Illuminate\Support\Facades\Route;

Route::controller(SavingController::class)->prefix('savings')->name('savings.')->group(function (): void {
    Route::get('customers/{customer}', 'show')->name('customers.show');
    Route::post('customers/{customer}/deposits', 'deposit')->name('customers.deposit');
    Route::post('customers/{customer}/withdrawals', 'withdraw')->name('customers.withdraw');
    Route::post('transactions/{saving}/reverse', 'reverse')->name('transactions.reverse');
    Route::get('deposits', 'deposits')->name('deposits');
    Route::get('withdrawals', 'withdrawals')->name('withdrawals');
    Route::get('balances', 'balances')->name('balances');
    Route::get('branch-balances', 'branchBalances')->name('branch-balances');
});

<?php

use App\Http\Controllers\Api\V1\Payments\TellerController;
use Illuminate\Support\Facades\Route;

Route::controller(TellerController::class)->prefix('teller')->name('teller.')->group(function (): void {
    Route::get('customers/{customer}', 'show')->name('customers.show');
    Route::post('customers/{customer}/deposit', 'deposit')->name('customers.deposit');
    Route::get('cash', 'cash')->name('cash');
    Route::get('receipts/{payment}', 'receipt')->name('receipts.show');
    Route::get('bank-deposits', 'bankDeposits')->name('bank-deposits.index');
    Route::post('bank-deposits', 'storeBankDeposit')->name('bank-deposits.store');
    Route::put('bank-deposits/{tellerDeposit}', 'updateBankDeposit')->name('bank-deposits.update');
    Route::get('bank-accounts', 'bankAccounts')->name('bank-accounts');
});

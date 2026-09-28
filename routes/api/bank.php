<?php

use App\Http\Controllers\Api\V1\Bank\BankAccountController;
use App\Http\Controllers\Api\V1\Bank\BankTransferController;
use App\Http\Controllers\Api\V1\Bank\PayrollController;
use Illuminate\Support\Facades\Route;

Route::prefix('bank')->name('bank.')->group(function (): void {
    Route::get('balances', [BankAccountController::class, 'balances'])->name('balances');
    Route::get('options/accounts', [BankAccountController::class, 'options'])->name('options.accounts');
    Route::apiResource('accounts', BankAccountController::class)->except('show')->parameters(['accounts' => 'bankAccount']);

    Route::controller(BankTransferController::class)->group(function (): void {
        Route::post('transfers/{bankTransfer}/approve', 'approve')->name('transfers.approve');
        Route::post('transfers/{bankTransfer}/reject', 'reject')->name('transfers.reject');
        Route::get('petty-cash', 'pettyCashIndex')->name('petty-cash.index');
        Route::post('petty-cash', 'pettyCashStore')->name('petty-cash.store');
        Route::get('reserve-to-investment', 'reserveToInvestmentIndex')->name('reserve-to-investment.index');
        Route::post('reserve-to-investment', 'reserveToInvestmentStore')->name('reserve-to-investment.store');
        Route::get('reserve-to-principal', 'reserveToPrincipalIndex')->name('reserve-to-principal.index');
        Route::post('reserve-to-principal', 'reserveToPrincipalStore')->name('reserve-to-principal.store');
        Route::get('company-transfers', 'companyIndex')->name('company-transfers.index');
        Route::post('company-transfers', 'companyStore')->name('company-transfers.store');
        Route::post('transfers/{bankTransfer}/reverse', 'reverse')->name('transfers.reverse');
    });

    Route::controller(PayrollController::class)->group(function (): void {
        Route::get('payroll', 'index')->name('payroll.index');
        Route::get('payroll/{date}', 'show')->name('payroll.show');
    });
});

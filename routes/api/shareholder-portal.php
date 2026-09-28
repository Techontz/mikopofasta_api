<?php

use App\Http\Controllers\Api\V1\ShareholderPortal\ShareholderPortalController;
use Illuminate\Support\Facades\Route;

/*
| Shareholder Portal — the shareholder is always resolved from the signed-in account (never from a request id).
*/
Route::controller(ShareholderPortalController::class)->prefix('portal/shareholder')->name('portal.shareholder.')->group(function (): void {
    Route::get('dashboard', 'dashboard')->name('dashboard');
    Route::get('profile', 'profile')->name('profile.show');
    Route::put('profile', 'updateProfile')->name('profile.update');
    Route::get('profile/photo', 'photo')->name('profile.photo');
    Route::get('capital', 'capital')->name('capital.index');
    Route::post('capital', 'submitCapital')->name('capital.store');
    Route::post('capital/{capital}/cancel', 'cancelCapital')->whereNumber('capital')->name('capital.cancel');
    Route::get('capital/{capital}/receipt', 'receipt')->whereNumber('capital')->name('capital.receipt');
    Route::get('bank-accounts', 'bankAccounts')->name('bank-accounts');
    Route::get('shares', 'shares')->name('shares');
    Route::get('dividends', 'dividends')->name('dividends');
    Route::get('statement', 'statement')->name('statement');
    Route::get('statement/download', 'downloadStatement')->name('statement.download');
    Route::get('directory', 'shareholderDirectory')->name('directory');
    Route::get('share-structure', 'structure')->name('share-structure');
    Route::get('reserve-transfers', 'reserveTransfers')->name('reserve-transfers.index');
    Route::post('reserve-transfers/{bankTransfer}/approve', 'approveReserveTransfer')->whereNumber('bankTransfer')->name('reserve-transfers.approve');
    Route::post('reserve-transfers/{bankTransfer}/reject', 'rejectReserveTransfer')->whereNumber('bankTransfer')->name('reserve-transfers.reject');
});

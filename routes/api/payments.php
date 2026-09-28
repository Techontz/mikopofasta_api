<?php

use App\Http\Controllers\Api\V1\Payments\BranchReceiptController;
use App\Http\Controllers\Api\V1\Payments\CashVerificationController;
use App\Http\Controllers\Api\V1\Payments\ReconciliationController;
use App\Http\Controllers\Api\V1\Payments\StatementController;
use App\Http\Controllers\Api\V1\Payments\SuspenseController;
use Illuminate\Support\Facades\Route;

Route::prefix('payments')->name('payments.')->group(function (): void {
    Route::controller(CashVerificationController::class)->group(function (): void {
        Route::get('cash', 'index')->name('cash.index');
        Route::post('cash/{payment}/reject', 'reject')->name('cash.reject');
        Route::get('zone-options', 'zoneOptions')->name('zone-options');
    });

    Route::controller(ReconciliationController::class)->prefix('reconciliation')->name('reconciliation.')->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('{tellerDeposit}/verify', 'verify')->name('verify');
        Route::post('{tellerDeposit}/confirm', 'confirm')->name('confirm');
        Route::post('{tellerDeposit}/reject', 'reject')->name('reject');
    });

    Route::controller(SuspenseController::class)->group(function (): void {
        Route::get('suspense', 'index')->name('suspense.index');
        Route::post('unmatched', 'store')->name('unmatched.store');
        Route::post('confirmed', 'storeConfirmed')->name('confirmed.store');
        Route::post('suspense/{payment}/allocate', 'allocate')->name('suspense.allocate');
        Route::post('suspense/{payment}/flag', 'flag')->name('suspense.flag');
        Route::post('suspense/{payment}/refund', 'refund')->name('suspense.refund');
        Route::get('loan-options', 'loanOptions')->name('loan-options');
    });

    Route::controller(BranchReceiptController::class)->prefix('branch-receipts')->name('branch-receipts.')->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('{payment}/approve', 'approve')->whereNumber('payment')->name('approve');
        Route::post('{payment}/reject', 'reject')->whereNumber('payment')->name('reject');
    });

    Route::get('statement/{customer}', StatementController::class)->name('statement');
});

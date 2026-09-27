<?php

use App\Http\Controllers\Api\V1\Shares\ShareHolderShareController;
use App\Http\Controllers\Api\V1\Shares\ShareOptionController;
use App\Http\Controllers\Api\V1\Shares\ShareOverviewController;
use App\Http\Controllers\Api\V1\Shares\ShareReportController;
use App\Http\Controllers\Api\V1\Shares\ShareTransactionController;
use App\Http\Controllers\Api\V1\Shares\ShareValuationController;
use Illuminate\Support\Facades\Route;

Route::prefix('shares')->name('shares.')->group(function (): void {
    Route::get('overview', [ShareOverviewController::class, 'index'])->name('overview');
    Route::get('structure', [ShareOverviewController::class, 'structure'])->name('structure.show');
    Route::post('structure', [ShareOverviewController::class, 'store'])->name('structure.store');
    Route::patch('structure', [ShareOverviewController::class, 'update'])->name('structure.update');

    Route::get('register', [ShareHolderShareController::class, 'register'])->name('register');
    Route::get('share-holders', [ShareHolderShareController::class, 'index'])->name('share-holders.index');
    Route::get('share-holders/{shareHolder}', [ShareHolderShareController::class, 'show'])->name('share-holders.show');
    Route::get('share-holders/{shareHolder}/photo', [ShareHolderShareController::class, 'photo'])->name('share-holders.photo');

    Route::controller(ShareTransactionController::class)->group(function (): void {
        Route::get('transactions', 'index')->name('transactions.index');
        Route::get('transactions/{shareTransaction}', 'show')->name('transactions.show');
        Route::get('transactions/{shareTransaction}/document', 'document')->name('transactions.document');
        Route::post('transactions/{shareTransaction}/reverse', 'reverse')->name('transactions.reverse');
        Route::post('issuances', 'issue')->name('issuances.store');
        Route::get('issuance-requests', 'issuanceRequests')->name('issuance-requests.index');
        Route::post('issuance-requests/{issuanceRequest}/approve', 'approveIssuanceRequest')->whereNumber('issuanceRequest')->name('issuance-requests.approve');
        Route::post('issuance-requests/{issuanceRequest}/reject', 'rejectIssuanceRequest')->whereNumber('issuanceRequest')->name('issuance-requests.reject');
        Route::post('transfers', 'transfer')->name('transfers.store');
        Route::post('cancellations', 'cancel')->name('cancellations.store');
        Route::post('adjustments', 'adjust')->name('adjustments.store');
    });

    Route::get('valuations', [ShareValuationController::class, 'index'])->name('valuations.index');
    Route::post('valuations', [ShareValuationController::class, 'store'])->name('valuations.store');
    Route::post('valuations/{shareValuation}/reverse', [ShareValuationController::class, 'reverse'])->name('valuations.reverse');

    Route::controller(ShareReportController::class)->prefix('reports')->name('reports.')->group(function (): void {
        Route::get('ownership', 'ownership')->name('ownership');
        Route::get('distribution', 'distribution')->name('distribution');
        Route::get('valuations', 'valuations')->name('valuations');
        Route::get('transactions', 'transactions')->name('transactions');
        Route::get('issuances', 'issuances')->name('issuances');
        Route::get('transfers', 'transfers')->name('transfers');
    });

    Route::controller(ShareOptionController::class)->prefix('options')->name('options.')->group(function (): void {
        Route::get('share-holders', 'shareHolders')->name('share-holders');
        Route::get('contributions', 'contributions')->name('contributions');
        Route::get('bank-accounts', 'bankAccounts')->name('bank-accounts');
        Route::get('types', 'types')->name('types');
    });
});

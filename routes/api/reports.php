<?php

use App\Http\Controllers\Api\V1\Reports\LiveReportController;
use App\Http\Controllers\Api\V1\Reports\PortfolioReportController;
use Illuminate\Support\Facades\Route;

// Live & portfolio
Route::prefix('reports')->name('reports.')->group(function (): void {
    Route::controller(LiveReportController::class)->group(function (): void {
        Route::get('cash', 'cash')->name('cash');
        Route::get('branchwise', 'branchwise')->name('branchwise');
        Route::get('file', 'file')->name('file');
        Route::get('file/new-loans', 'newLoans')->name('file.new-loans');
        Route::get('file/historical-payments', 'historicalPayments')->name('file.historical-payments');
        Route::get('penalties', 'penalties')->name('penalties');
        Route::get('pending', 'pending')->name('pending');
        Route::get('repayment', 'repayment')->name('repayment');
        Route::get('default', 'default')->name('default');
        Route::get('write-off', 'writeOff')->name('write-off');
        Route::get('collection', 'collection')->name('collection');
        Route::get('statement', 'statement')->name('statement');
        Route::get('receivable', 'receivable')->name('receivable');
        Route::get('received', 'received')->name('received');
        Route::get('daily', 'daily')->name('daily');
        Route::get('development', 'development')->name('development');
        Route::get('development/{customer}', 'developmentShow')->whereNumber('customer')->name('development.show');
    });

    Route::controller(PortfolioReportController::class)->group(function (): void {
        Route::get('portfolio', 'portfolio')->name('portfolio');
        Route::get('collections', 'collections')->name('collections');
        Route::get('arrears', 'arrears')->name('arrears');
        Route::get('recovery', 'recovery')->name('recovery');
        Route::get('behaviour', 'behaviour')->name('behaviour');
        Route::get('segmentation', 'segmentation')->name('segmentation');
        Route::get('age-analysis', 'ageAnalysis')->name('age-analysis');
    });
});

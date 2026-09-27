<?php

use App\Http\Controllers\Api\V1\Accounting\AuditTrailController;
use App\Http\Controllers\Api\V1\Accounting\ChartOfAccountsController;
use App\Http\Controllers\Api\V1\Accounting\JournalController;
use App\Http\Controllers\Api\V1\Accounting\LedgerIntegrityController;
use App\Http\Controllers\Api\V1\Accounting\PeriodCloseController;
use Illuminate\Support\Facades\Route;

Route::prefix('accounting')->name('accounting.')->group(function (): void {
    Route::get('accounts', [ChartOfAccountsController::class, 'index'])->name('accounts.index');
    Route::get('account-options', [ChartOfAccountsController::class, 'options'])->name('accounts.options');

    Route::get('journal', [JournalController::class, 'index'])->name('journal.index');
    Route::get('journal-sources', [JournalController::class, 'sources'])->name('journal.sources');
    Route::get('journal-transaction-types', [JournalController::class, 'transactionTypes'])->name('journal.transaction-types');
    Route::get('journal/{journalEntry}', [JournalController::class, 'show'])->name('journal.show');
    Route::post('journal/{journalEntry}/reverse', [JournalController::class, 'reverse'])->name('journal.reverse');

    Route::get('periods', [PeriodCloseController::class, 'index'])->name('periods.index');
    Route::post('periods', [PeriodCloseController::class, 'store'])->name('periods.store');
    Route::get('periods/{period}', [PeriodCloseController::class, 'show'])->name('periods.show');
    Route::post('periods/{period}/close', [PeriodCloseController::class, 'close'])->name('periods.close');

    Route::get('audit', [AuditTrailController::class, 'index'])->name('audit.index');
    Route::get('audit-models', [AuditTrailController::class, 'models'])->name('audit.models');

    Route::get('integrity', LedgerIntegrityController::class)->name('integrity');
});

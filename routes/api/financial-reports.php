<?php

use App\Http\Controllers\Api\V1\Reports\Financial\BalanceSheetController;
use App\Http\Controllers\Api\V1\Reports\Financial\CashFlowController;
use App\Http\Controllers\Api\V1\Reports\Financial\ControlReportController;
use App\Http\Controllers\Api\V1\Reports\Financial\FundPositionController;
use App\Http\Controllers\Api\V1\Reports\Financial\PeriodResultController;
use App\Http\Controllers\Api\V1\Reports\Financial\ProfitLossController;
use Illuminate\Support\Facades\Route;

Route::prefix('reports/financial')->name('reports.financial.')->group(function (): void {
    Route::get('cash-flow', [CashFlowController::class, 'index'])->name('cash-flow');
    Route::get('daily-position', [CashFlowController::class, 'dailyPosition'])->name('daily-position');
    Route::get('branch-pnl', [ProfitLossController::class, 'branches'])->name('branch-pnl');
    Route::get('branch-ranking', [ProfitLossController::class, 'ranking'])->name('branch-ranking');
    Route::get('profit-loss', [ProfitLossController::class, 'consolidated'])->name('profit-loss');
    Route::get('hq-hold', [PeriodResultController::class, 'hqHold'])->name('hq-hold');
    Route::get('loss-carry-forward', [PeriodResultController::class, 'lossCarryForward'])->name('loss-carry-forward');
    Route::get('balance-sheet', BalanceSheetController::class)->name('balance-sheet');
    Route::get('fund-position', FundPositionController::class)->name('fund-position');
    Route::get('expenses', [ControlReportController::class, 'expenses'])->name('expenses');
    Route::get('suspense', [ControlReportController::class, 'suspense'])->name('suspense');
    Route::get('reversals', [ControlReportController::class, 'reversals'])->name('reversals');
});

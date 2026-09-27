<?php

use App\Http\Controllers\Api\V1\Goals\GoalController;
use Illuminate\Support\Facades\Route;

Route::prefix('goals')->name('goals.')->group(function (): void {
    Route::get('options', [GoalController::class, 'options'])->name('options');
    Route::get('report', [GoalController::class, 'report'])->name('report');
});

Route::apiResource('goals', GoalController::class);

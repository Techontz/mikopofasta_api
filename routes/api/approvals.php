<?php

use App\Http\Controllers\Api\V1\Approvals\ApprovalPolicyController;
use App\Http\Controllers\Api\V1\Approvals\PendingApprovalController;
use Illuminate\Support\Facades\Route;

Route::get('approvals/pending', [PendingApprovalController::class, 'index'])->name('approvals.pending');

Route::controller(ApprovalPolicyController::class)->prefix('settings')->name('settings.')->group(function (): void {
    Route::get('approval-policies', 'index')->name('approval-policies.index');
    Route::put('approval-policies', 'update')->name('approval-policies.update');
});

<?php

use App\Http\Controllers\Api\V1\Crm\CrmController;
use App\Http\Controllers\Api\V1\Crm\InteractionController;
use App\Http\Controllers\Api\V1\Crm\TicketController;
use Illuminate\Support\Facades\Route;

Route::prefix('crm')->name('crm.')->group(function (): void {
    Route::get('summary', [CrmController::class, 'summary'])->name('summary');
    Route::get('options', [CrmController::class, 'options'])->name('options');
    Route::get('report', [CrmController::class, 'report'])->name('report');
    Route::get('customers/{customer}', [CrmController::class, 'customer'])->name('customers.show');

    Route::get('interactions', [InteractionController::class, 'index'])->name('interactions.index');
    Route::post('calls', [InteractionController::class, 'storeCall'])->name('calls.store');
    Route::post('sms', [InteractionController::class, 'storeSms'])->name('sms.store');
    Route::post('sms/bulk', [InteractionController::class, 'bulkSms'])->name('sms.bulk');
    Route::get('follow-ups', [InteractionController::class, 'followUps'])->name('follow-ups.index');
    Route::post('follow-ups/{interaction}/complete', [InteractionController::class, 'completeFollowUp'])->name('follow-ups.complete');

    Route::apiResource('tickets', TicketController::class)->except(['destroy']);
});

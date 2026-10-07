<?php

use App\Http\Controllers\Api\V1\Sms\SmsContactGroupController;
use App\Http\Controllers\Api\V1\Sms\SmsController;
use App\Http\Controllers\Api\V1\Sms\SmsTemplateController;
use Illuminate\Support\Facades\Route;

Route::prefix('sms')->name('sms.')->group(function (): void {
    Route::get('options', [SmsController::class, 'options'])->name('options');
    Route::get('balance', [SmsController::class, 'balance'])->name('balance');
    Route::post('preview', [SmsController::class, 'preview'])->name('preview');
    Route::post('send', [SmsController::class, 'send'])->name('send');
    Route::get('logs', [SmsController::class, 'logs'])->name('logs');

    Route::apiResource('templates', SmsTemplateController::class)->except('show');
    Route::apiResource('contact-groups', SmsContactGroupController::class)->parameters(['contact-groups' => 'contactGroup']);
});

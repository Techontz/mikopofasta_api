<?php

use App\Http\Controllers\Api\V1\Messages\MessageController;
use Illuminate\Support\Facades\Route;

Route::prefix('messages')->name('messages.')->controller(MessageController::class)->group(function (): void {
    Route::get('conversations', 'conversations')->name('conversations.index');
    Route::post('conversations', 'start')->name('conversations.store');
    Route::get('conversations/{conversation}', 'show')->name('conversations.show');
    Route::post('conversations/{conversation}/messages', 'send')->name('conversations.messages.store');
    Route::get('contacts', 'contacts')->name('contacts');
    Route::get('unread', 'unread')->name('unread');
    Route::post('groups', 'group')->name('groups.store');
    Route::post('broadcasts', 'broadcast')->name('broadcasts.store');
});

<?php

use App\Http\Controllers\Api\V1\Groups\GroupController;
use Illuminate\Support\Facades\Route;

Route::controller(GroupController::class)->prefix('groups')->name('groups.')->group(function (): void {
    Route::get('/', 'index')->name('index');
    Route::get('options', 'options')->name('options');
    Route::post('/', 'store')->name('store');
    Route::get('{group}', 'show')->whereNumber('group')->name('show');
    Route::put('{group}', 'update')->whereNumber('group')->name('update');
    Route::delete('{group}', 'destroy')->whereNumber('group')->name('destroy');
});

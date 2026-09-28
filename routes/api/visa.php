<?php

use App\Http\Controllers\Api\V1\Visa\VisaController;
use Illuminate\Support\Facades\Route;

Route::controller(VisaController::class)->prefix('visa')->name('visa.')->group(function (): void {
    Route::get('customers', 'index')->name('customers.index');
    Route::put('customers/{customer}', 'update')->name('customers.update');
});

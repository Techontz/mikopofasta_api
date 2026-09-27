<?php

use App\Http\Controllers\Api\V1\MasterData\GeographyController;
use App\Http\Controllers\Api\V1\MasterData\MasterDataController;
use App\Http\Controllers\Api\V1\MasterData\RegistrationRequirementController;
use App\Http\Controllers\Api\V1\Settings\CustomerCategoryController;
use App\Services\Customers\MasterDataRegistry;
use Illuminate\Support\Facades\Route;

/*
| Customer Module configuration and lookups (CUSTOMER_MODULE_IMPLEMENTATION.md §3.3).
*/

Route::get('registration/requirements', RegistrationRequirementController::class)->name('registration.requirements');

Route::apiResource('customer-categories', CustomerCategoryController::class);
// Read-only alias: the selectable (active) customer types, from the same controller, query and resource.
Route::get('customer-types', [CustomerCategoryController::class, 'types'])->name('customer-types.index');

Route::controller(GeographyController::class)->group(function (): void {
    Route::get('regions', 'regions')->name('geography.regions');
    Route::get('districts', 'districts')->name('geography.districts');
    Route::get('wards', 'wards')->name('geography.wards');
    Route::get('master-data/geography', 'status')->name('geography.status');
    Route::post('master-data/geography/import', 'import')->name('geography.import');
});

Route::controller(MasterDataController::class)->prefix('master-data')->name('master-data.')->group(function (): void {
    $slugs = implode('|', array_map('preg_quote', (new MasterDataRegistry)->slugs()));

    Route::get('/', 'index')->name('index');
    // Declared before the generic {list} routes; an unknown list is a 404 inside the action.
    Route::get('parented/{list}', 'parented')->name('parented');
    Route::get('{list}', 'show')->where('list', $slugs)->name('show');
    Route::post('{list}', 'store')->where('list', $slugs)->name('store');
    Route::put('{list}/{id}', 'update')->where('list', $slugs)->whereNumber('id')->name('update');
    Route::delete('{list}/{id}', 'destroy')->where('list', $slugs)->whereNumber('id')->name('destroy');
});

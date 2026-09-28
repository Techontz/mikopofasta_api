<?php

use App\Http\Controllers\Api\V1\Imports\LegacyImportController;
use Illuminate\Support\Facades\Route;

Route::controller(LegacyImportController::class)->prefix('legacy-imports')->name('legacy-imports.')->group(function (): void {
    Route::get('/', 'index')->name('index');
    Route::get('template', 'template')->name('template');
    Route::get('export', 'export')->name('export');
    Route::post('/', 'store')->name('store');
    Route::get('{legacyImport}', 'show')->whereNumber('legacyImport')->name('show');
    Route::delete('{legacyImport}', 'destroy')->whereNumber('legacyImport')->name('destroy');
    Route::get('{legacyImport}/rows', 'rows')->whereNumber('legacyImport')->name('rows');
    Route::get('{legacyImport}/exceptions', 'exceptions')->whereNumber('legacyImport')->name('exceptions');
    Route::get('{legacyImport}/customers', 'customers')->whereNumber('legacyImport')->name('customers');
    Route::post('{legacyImport}/submit', 'submit')->whereNumber('legacyImport')->name('submit');
    Route::post('{legacyImport}/approve', 'approve')->whereNumber('legacyImport')->name('approve');
    Route::post('{legacyImport}/reject', 'reject')->whereNumber('legacyImport')->name('reject');
    Route::post('{legacyImport}/rollback', 'rollback')->whereNumber('legacyImport')->name('rollback');
    Route::post('{legacyImport}/rows/{row}/map', 'mapRow')->whereNumber(['legacyImport', 'row'])->name('rows.map');
});

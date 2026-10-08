<?php

use App\Http\Controllers\Api\V1\Customers\CustomerApprovalController;
use App\Http\Controllers\Api\V1\Customers\CustomerController;
use App\Http\Controllers\Api\V1\Customers\CustomerNoteController;
use App\Http\Controllers\Api\V1\Customers\DocumentController;
use App\Http\Controllers\Api\V1\Customers\FaceScanController;
use App\Http\Controllers\Api\V1\Customers\GuarantorController;
use App\Http\Controllers\Api\V1\Customers\NextOfKinController;
use App\Http\Controllers\Api\V1\Customers\RegistrationDraftController;
use Illuminate\Support\Facades\Route;

Route::prefix('customer-drafts')->name('customer-drafts.')->controller(RegistrationDraftController::class)->group(function (): void {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::get('{draft}', 'show')->whereNumber('draft')->name('show');
    Route::post('{draft}/submitted', 'submitted')->whereNumber('draft')->name('submitted');
    Route::delete('{draft}', 'destroy')->whereNumber('draft')->name('destroy');
});

Route::prefix('customers')->name('customers.')->group(function (): void {
    Route::get('registration-options', [CustomerController::class, 'registrationOptions'])->name('registration-options');

    Route::controller(CustomerApprovalController::class)->group(function (): void {
        Route::get('pending-approval', 'pending')->name('pending-approval');
        Route::post('{customer}/approve', 'approve')->whereNumber('customer')->name('approve');
        Route::post('{customer}/reject', 'reject')->whereNumber('customer')->name('reject');
        Route::post('{customer}/resubmit', 'resubmit')->whereNumber('customer')->name('resubmit');
    });

    Route::controller(DocumentController::class)->group(function (): void {
        Route::get('{customer}/documents', 'index')->whereNumber('customer')->name('documents.index');
        Route::post('{customer}/documents', 'store')->whereNumber('customer')->name('documents.store');
        Route::get('{customer}/documents/{document}/download', 'download')->whereNumber(['customer', 'document'])->name('documents.download');
        Route::delete('{customer}/documents/{document}', 'destroy')->whereNumber(['customer', 'document'])->name('documents.destroy');
    });

    Route::controller(FaceScanController::class)->group(function (): void {
        Route::post('{customer}/face-verify', 'verify')->whereNumber('customer')->name('face-verify');
        Route::get('{customer}/face-scans', 'index')->whereNumber('customer')->name('face-scans.index');
        Route::get('{customer}/face-scans/{scan}/image', 'image')->whereNumber(['customer', 'scan'])->name('face-scans.image');
    });

    Route::controller(NextOfKinController::class)->group(function (): void {
        Route::get('{customer}/next-of-kin', 'index')->whereNumber('customer')->name('next-of-kin.index');
        Route::post('{customer}/next-of-kin', 'store')->whereNumber('customer')->name('next-of-kin.store');
        Route::delete('{customer}/next-of-kin/{nextOfKin}', 'destroy')->whereNumber(['customer', 'nextOfKin'])->name('next-of-kin.destroy');
    });

    Route::controller(GuarantorController::class)->group(function (): void {
        Route::get('{customer}/guarantors', 'index')->whereNumber('customer')->name('guarantors.index');
        Route::post('{customer}/guarantors', 'store')->whereNumber('customer')->name('guarantors.store');
        Route::delete('{customer}/guarantors/{guarantor}', 'destroy')->whereNumber(['customer', 'guarantor'])->name('guarantors.destroy');
    });

    Route::controller(CustomerNoteController::class)->group(function (): void {
        Route::get('{customer}/notes', 'index')->whereNumber('customer')->name('notes.index');
        Route::post('{customer}/notes', 'store')->whereNumber('customer')->name('notes.store');
    });

    Route::controller(CustomerController::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('{customer}', 'show')->whereNumber('customer')->name('show');
        Route::put('{customer}', 'update')->whereNumber('customer')->name('update');
        Route::delete('{customer}', 'destroy')->whereNumber('customer')->name('destroy');
        Route::get('{customer}/kyc-status', 'kycStatus')->whereNumber('customer')->name('kyc-status');
        Route::get('{customer}/overview', 'overview')->whereNumber('customer')->name('overview');
        Route::get('{customer}/debt', 'debt')->whereNumber('customer')->name('debt');
        Route::get('{customer}/timeline', 'timeline')->whereNumber('customer')->name('timeline');
        Route::get('{customer}/credit-history', 'creditHistory')->whereNumber('customer')->name('credit-history');
        Route::get('{customer}/audit-trail', 'auditTrail')->whereNumber('customer')->name('audit-trail');
        Route::get('{customer}/eligibility', 'eligibility')->whereNumber('customer')->name('eligibility');
        Route::get('{customer}/balance', 'balance')->whereNumber('customer')->name('balance');
        Route::get('{customer}/photo', 'photo')->whereNumber('customer')->name('photo');
        Route::post('{customer}/mark', 'mark')->whereNumber('customer')->name('mark');
        Route::post('{customer}/sms', 'sendSms')->whereNumber('customer')->name('sms');
    });
});

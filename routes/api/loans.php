<?php

use App\Http\Controllers\Api\V1\Loans\CreditAssessmentController;
use App\Http\Controllers\Api\V1\Loans\LoanController;
use App\Http\Controllers\Api\V1\Loans\LoanRecoveryController;
use App\Http\Controllers\Api\V1\Loans\LoanSecurityController;
use App\Http\Controllers\Api\V1\Loans\LoanWorkflowController;
use Illuminate\Support\Facades\Route;

Route::prefix('loans')->name('loans.')->group(function (): void {
    Route::controller(LoanController::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('preview', 'preview')->name('preview');
        Route::get('withdrawals', 'withdrawals')->name('withdrawals');
        Route::get('customers/{customer}/categories', 'categories')->whereNumber('customer')->name('categories');
        Route::get('customers/{customer}/guarantor-candidates', 'guarantorCandidates')->whereNumber('customer')->name('customer-guarantor-candidates');
        Route::get('{loan}', 'show')->whereNumber('loan')->name('show');
        Route::put('{loan}', 'update')->whereNumber('loan')->name('update');
        Route::delete('{loan}', 'destroy')->whereNumber('loan')->name('destroy');
    });

    // Credit assessment engine (§36 / §37 / §63): advisory recommendation with its evidence. Reads and records only.
    Route::controller(CreditAssessmentController::class)->group(function (): void {
        Route::get('credit-assessments/queue', 'queue')->name('credit-assessment.queue');
        Route::get('{loan}/credit-assessment', 'show')->whereNumber('loan')->name('credit-assessment.show');
        Route::post('{loan}/credit-assessment', 'store')->whereNumber('loan')->name('credit-assessment.store');
        Route::get('{loan}/credit-assessments', 'index')->whereNumber('loan')->name('credit-assessment.index');
    });

    Route::controller(LoanSecurityController::class)->group(function (): void {
        Route::get('{loan}/guarantor-candidates', 'guarantorCandidates')->whereNumber('loan')->name('guarantors.candidates');
        Route::post('{loan}/guarantors', 'storeGuarantor')->name('guarantors.store');
        Route::delete('{loan}/guarantors/{guarantor}', 'destroyGuarantor')->name('guarantors.destroy');
        Route::post('{loan}/collaterals', 'storeCollateral')->name('collaterals.store');
        Route::delete('{loan}/collaterals/{collateral}', 'destroyCollateral')->name('collaterals.destroy');
        Route::post('{loan}/collateral-attachment', 'updateAttachment')->name('collateral-attachment');
    });

    Route::controller(LoanWorkflowController::class)->group(function (): void {
        Route::post('overdue/process', 'processOverdue')->name('overdue.process');
        Route::post('{loan}/approve-manager', 'approveManager')->name('approve-manager');
        Route::post('{loan}/reject', 'reject')->name('reject');
        Route::post('{loan}/modify', 'modify')->name('modify');
        Route::post('{loan}/e-mandate', 'createMandate')->name('mandate.store');
        Route::post('{loan}/e-mandate/verify-otp', 'verifyMandateOtp')->name('mandate.verify-otp');
        Route::post('{loan}/kyc-verify', 'verifyTelco')->name('kyc-verify');
        Route::post('{loan}/approve-credit', 'approveCredit')->name('approve-credit');
        Route::post('{loan}/prepare-disbursement', 'prepareDisbursement')->name('prepare-disbursement');
        Route::get('{loan}/disbursement-sources', 'disbursementSources')->name('disbursement-sources');
        Route::post('{loan}/disburse', 'disburse')->name('disburse');
        Route::post('{loan}/retry-disbursement', 'retry')->name('retry-disbursement');
        Route::post('{loan}/escalation', 'escalation')->name('escalation');
        Route::post('{loan}/requeue', 'requeue')->name('requeue');
        Route::post('{loan}/confirm-disbursement', 'confirmDisbursement')->name('confirm-disbursement');
        Route::post('{loan}/cash-out', 'cashOut')->name('cash-out');
        Route::post('{loan}/close', 'close')->name('close');
        Route::post('{loan}/write-off', 'writeOff')->name('write-off');
        Route::get('write-off-requests', 'writeOffRequests')->name('write-off-requests.index');
        Route::post('write-off-requests/{writeOffRequest}/approve', 'approveWriteOff')->whereNumber('writeOffRequest')->name('write-off-requests.approve');
        Route::post('write-off-requests/{writeOffRequest}/reject', 'rejectWriteOff')->whereNumber('writeOffRequest')->name('write-off-requests.reject');
        Route::post('{loan}/transactions/{loanTransaction}/reverse', 'reverseRepayment')->whereNumber(['loan', 'loanTransaction'])->name('transactions.reverse');
        Route::post('{loan}/reverse-disbursement', 'reverseDisbursement')->whereNumber('loan')->name('reverse-disbursement');
        Route::post('{loan}/comments', 'comment')->name('comments');
        Route::get('{loan}/agreement', 'agreement')->whereNumber('loan')->name('agreement.show');
        Route::post('{loan}/agreement', 'uploadAgreement')->name('agreement');
    });

    Route::controller(LoanRecoveryController::class)->group(function (): void {
        Route::get('{loan}/recoveries', 'index')->whereNumber('loan')->name('recoveries.index');
        Route::post('{loan}/recoveries', 'store')->whereNumber('loan')->name('recoveries.store');
        Route::post('{loan}/recoveries/{recovery}/reverse', 'reverse')->whereNumber(['loan', 'recovery'])->name('recoveries.reverse');
    });
});

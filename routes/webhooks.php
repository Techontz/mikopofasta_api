<?php

use App\Http\Controllers\Api\V1\Loans\BankMandateWebhookController;
use App\Http\Controllers\Api\V1\Loans\LindaWebhookController;
use App\Http\Controllers\Api\V1\Loans\VodacomDisbursementWebhookController;
use App\Http\Controllers\Api\V1\Payments\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public provider callbacks (no session / token auth; each endpoint verifies its own signature)
|--------------------------------------------------------------------------
| Registered in bootstrap/app.php under the "api" middleware group with the /api prefix.
*/

// Loans
Route::post('webhooks/vodacom/disbursement-status', VodacomDisbursementWebhookController::class)->middleware('throttle:120,1')->name('webhooks.vodacom.disbursement');
Route::post('webhooks/bank-mandate/collections', BankMandateWebhookController::class)->middleware('throttle:120,1')->name('webhooks.bank-mandate.collections');
// eSIMU: Linda Device Lock confirmations of lock / unlock commands.
Route::post('webhooks/linda', LindaWebhookController::class)->middleware('throttle:240,1')->name('webhooks.linda');

// Payments
Route::post('webhooks/payments', PaymentWebhookController::class)->middleware('throttle:120,1')->name('webhooks.payments');

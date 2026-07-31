<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Webhook Routes — /webhooks
|--------------------------------------------------------------------------
|
| Inbound provider callbacks (Vodacom disbursement status, mobile-money and
| bank payment notifications). These are NOT Sanctum-authenticated: each is
| verified against a provider-specific HMAC signature header and an
| Idempotency-Key, per backend spec §1.
|
| Routes are registered here as the Loan Origination and Repayment modules
| ship; the file is mounted now so the mount point is fixed and versioned
| independently of /api/v1.
|
*/

use App\Http\Controllers\Loans\DisbursementCallbackController;
use App\Http\Controllers\Repayments\PaymentController;
use Illuminate\Support\Facades\Route;

/*
 * POST /webhooks/payments — inbound provider payment (§15.3).
 *
 * Not Sanctum-authenticated: a provider callback carries no bearer token. The
 * HMAC signature IS the credential (§1), and `webhook.signature` runs before
 * anything else — before the Form Request, before the controller, before a row
 * is written.
 *
 * Duplicate protection is now fourfold (§7 plus §1): the UNIQUE index on
 * payments.transaction_id, an explicit check in the action, the
 * Idempotency-Key replay window, and the signature's timestamp tolerance.
 */
Route::post('/payments', [PaymentController::class, 'webhook'])
    ->middleware(['webhook.signature:payments', 'idempotency'])
    ->name('payments');

/*
 * POST /webhooks/vodacom/disbursement-status — §15.2.
 *
 * §6: "No ledger entry exists until a disbursement batch reaches success."
 * This callback is that moment. The system never assumes success from its own
 * outbound call, which is why settling is a separate inbound event rather than
 * something POST /loans/{loan}/prepare-disbursement could do for itself.
 *
 * Repeated callbacks are safe twice over: the batch's own status is an
 * idempotency marker, so a second success posts nothing, and the
 * Idempotency-Key replay window returns the original response verbatim.
 *
 * Signature verification runs first, so an unsigned caller never reaches the
 * action that activates a loan.
 */
Route::post('/vodacom/disbursement-status', [DisbursementCallbackController::class, 'webhook'])
    ->middleware(['webhook.signature:vodacom', 'idempotency'])
    ->name('vodacom.disbursement-status');

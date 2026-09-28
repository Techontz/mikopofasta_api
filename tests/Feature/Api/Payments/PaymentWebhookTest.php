<?php

namespace Tests\Feature\Api\Payments;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Enums\PaymentStatus;
use App\Integrations\Payments\TestPaymentWebhookConnector;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Models\SmsLog;
use App\Services\LoanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Direct payment: POST /webhooks/payments → match reference/phone → allocate; else suspense; idempotent.
 */
class PaymentWebhookTest extends TestCase
{
    use InteractsWithRepayments;
    use RefreshDatabase;

    public function test_signature_is_required(): void
    {
        $this->postJson('/api/webhooks/payments', ['transaction_id' => 'T1', 'amount' => 1000, 'channel' => 'VODACOM'], ['X-Signature' => 'bad'])->assertUnauthorized();
        $this->assertSame(0, Payment::count());
    }

    public function test_matched_reference_is_allocated_principal_penalty_interest_with_sms(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin, penalty: 10000);

        $this->webhook(['reference' => $loan->loan_number, 'amount' => 120000, 'phone' => '255700000001', 'channel' => 'VODACOM', 'transaction_id' => 'TXN001'])
            ->assertOk()->assertJsonPath('status', 'PAYMENT_SUCCESS');

        $transaction = $loan->transactions()->where('type', 'deposit')->sole();
        $this->assertEquals(100000, $transaction->principal);
        $this->assertEquals(10000, $transaction->penalty);
        $this->assertEquals(10000, $transaction->interest);
        $this->assertSame('VODACOM', $transaction->method);
        $this->assertSame(PaymentStatus::Allocated, Payment::sole()->status);
        $this->assertSame(20000.0, app(LoanService::class)->outstanding($loan->fresh())['total']);
        $this->assertTrue(SmsLog::where('customer_id', $loan->customer_id)->where('message', 'like', 'Tumepokea malipo yako%')->exists());
    }

    public function test_duplicate_transaction_id_is_ignored_and_flagged(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin);
        $payload = ['reference' => $loan->loan_number, 'amount' => 50000, 'channel' => 'BANK', 'transaction_id' => 'TXN-DUP'];

        $this->webhook($payload)->assertOk()->assertJsonPath('status', 'PAYMENT_SUCCESS');
        $this->webhook($payload)->assertOk()->assertJsonPath('status', 'DUPLICATE');

        $this->assertSame(1, Payment::count());
        $this->assertSame(1, $loan->transactions()->where('type', 'deposit')->count());
        $this->assertTrue(AuditLog::where('action', 'Payment.duplicate_webhook')->exists());
    }

    public function test_unmatched_payment_goes_to_suspense_and_finance_allocates_it(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin);

        $this->webhook(['reference' => 'WRONG', 'amount' => 40000, 'channel' => 'VODACOM', 'transaction_id' => 'TXN-X'])
            ->assertOk()->assertJsonPath('status', 'SUSPENSE');
        $payment = Payment::sole();
        $this->assertSame(PaymentStatus::Unallocated, $payment->status);
        $this->assertSame(40000.0, $this->balance($admin, Account::Suspense));

        $teller = $this->employeeWithRole($admin, 'teller');
        $this->actingAs($teller)->getJson('/api/v1/payments/suspense')->assertForbidden();

        $finance = $this->employeeWithRole($admin, 'finance');
        $this->actingAs($finance)->getJson('/api/v1/payments/suspense')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('suspense_balance', 40000);
        $this->actingAs($finance)->getJson('/api/v1/payments/loan-options')->assertOk()->assertJsonPath('data.0.value', (string) $loan->id);
        $this->actingAs($finance)->postJson("/api/v1/payments/suspense/{$payment->id}/allocate", ['loan_id' => $loan->id, 'amount' => 50000])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->actingAs($finance)->postJson("/api/v1/payments/suspense/{$payment->id}/allocate", ['loan_id' => $loan->id, 'amount' => 25000])->assertOk();
        $this->assertSame(PaymentStatus::Unallocated, $payment->fresh()->status);
        $this->assertSame(15000.0, $payment->fresh()->unallocated_amount);

        $this->actingAs($finance)->postJson("/api/v1/payments/suspense/{$payment->id}/allocate", ['loan_id' => $loan->id, 'amount' => 15000])->assertOk();
        $this->assertSame(PaymentStatus::Allocated, $payment->fresh()->status);
        $this->assertSame(0.0, $this->balance($admin, Account::Suspense));
        $this->assertSame(0.0, $this->balance($admin, Account::Bank));
        $this->assertSame(60000.0, $this->balance($admin, Account::LoanReceivable, $admin->branch_id));
        $this->assertSame(90000.0, app(LoanService::class)->outstanding($loan->fresh())['total']);
    }

    public function test_phone_match_and_overpayment_excess_is_held_in_suspense(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin, customer: ['phone' => '255712345678']);

        $this->webhook(['amount' => 150000, 'phone' => '0712345678', 'channel' => 'AIRTEL', 'transaction_id' => 'TXN-OVER'])
            ->assertOk()->assertJsonPath('status', 'PAYMENT_SUCCESS');

        $this->assertSame(LoanStatus::Closed, $loan->fresh()->status);
        $credit = Payment::whereNotNull('parent_id')->sole();
        $this->assertSame(PaymentStatus::Unallocated, $credit->status);
        $this->assertEquals(20000, $credit->amount);
        $this->assertSame($loan->customer_id, $credit->customer_id);
        // §11: one central pending account at HQ, never a branch-level one; the branch stays on the payment.
        $this->assertSame(20000.0, $this->balance($admin, Account::Suspense, null));
        $this->assertSame(0.0, $this->balance($admin, Account::Suspense, $admin->branch_id));

        $this->postJson("/api/v1/payments/suspense/{$credit->id}/flag", ['reason' => 'Confirm with customer'])->assertOk()->assertJsonPath('message', 'Payment flagged successfully');
        $this->postJson("/api/v1/payments/suspense/{$credit->id}/refund", ['reason' => 'Refunded to customer'])->assertOk();
        $this->assertSame(PaymentStatus::Refunded, $credit->fresh()->status);
        $this->assertSame(0.0, $this->balance($admin, Account::Suspense, $admin->branch_id));
    }

    public function test_finance_records_unmatched_statement_payment(): void
    {
        $admin = $this->signInAdmin();

        PaymentProvider::create(['company_id' => $admin->company_id, 'channel' => 'BANK', 'name' => 'CRDB Bank']);
        $this->postJson('/api/v1/payments/unmatched', ['amount' => 5000])->assertUnprocessable()->assertJsonValidationErrors(['channel', 'provider', 'paid_on']);
        $this->postJson('/api/v1/payments/unmatched', ['amount' => 5000, 'channel' => 'BANK', 'provider' => 'CRDB Bank', 'paid_on' => today()->toDateString(), 'transaction_id' => 'BK-1'])
            ->assertCreated()->assertJsonPath('data.status', 'unallocated');
        $this->assertMatchesRegularExpression('/^TX\d{6}[A-Z0-9]{10}$/', Payment::sole()->transaction_id, 'a typed transaction ID is ignored: the system generates it');

        $this->assertSame(5000.0, $this->balance($admin, Account::Suspense));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function webhook(array $payload): TestResponse
    {
        $body = json_encode($payload);
        $user = auth()->user();
        auth()->forgetGuards();

        $response = $this->call('POST', '/api/webhooks/payments', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, TestPaymentWebhookConnector::DEFAULT_SECRET),
        ], $body);

        auth()->forgetGuards();
        if ($user !== null) {
            $this->actingAs($user);
        }

        return $response;
    }
}

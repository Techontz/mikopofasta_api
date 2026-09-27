<?php

namespace Tests\Feature\Api\Payments;

use App\Enums\Account;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Models\SmsLog;
use App\Models\TellerDeposit;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cash → Teller (PENDING_VERIFICATION) → bank deposit → Finance verify → confirm → allocation + SMS.
 */
class TellerCashFlowTest extends TestCase
{
    use InteractsWithRepayments;
    use RefreshDatabase;

    public function test_teller_page_shows_loan_and_outstanding_breakdown(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin, penalty: 10000);

        $this->getJson("/api/v1/teller/customers/{$loan->customer_id}")
            ->assertOk()
            ->assertJsonPath('data.loan.id', $loan->id)
            ->assertJsonPath('data.outstanding.principal', 100000)
            ->assertJsonPath('data.outstanding.penalty', 10000)
            ->assertJsonPath('data.outstanding.interest', 30000)
            ->assertJsonPath('data.available_to_deposit', 140000)
            ->assertJsonStructure(['data' => ['customer' => ['full_name', 'customer_code', 'photo_url'], 'cashbook' => ['opening', 'deposit', 'withdrawal', 'closing'], 'statement']]);
    }

    public function test_teller_cash_is_pending_verification_and_held_in_teller_cash(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin);
        $teller = $this->employeeWithRole($admin, 'teller');

        $this->actingAs($teller)
            ->postJson("/api/v1/teller/customers/{$loan->customer_id}/deposit", ['depost' => '50,000', 'p_method' => 'CASH', 'recept' => true])
            ->assertCreated()
            ->assertJsonPath('message', 'Deposit successfully')
            ->assertJsonPath('data.status', 'pending_verification')
            ->assertJsonPath('receipt', true);

        $payment = Payment::sole();
        $this->assertNotNull($payment->receipt_number);
        $this->assertSame(50000.0, $this->balance($admin, Account::TellerCash, $admin->branch_id, employeeId: $teller->id));
        // §11: unverified cash is held in the one central HQ pending account; the payment keeps its branch.
        $this->assertSame(50000.0, $this->balance($admin, Account::Suspense, null));
        $this->assertSame(0.0, $this->balance($admin, Account::Suspense, $admin->branch_id));
        $this->assertSame($admin->branch_id, $payment->branch_id);
        $this->assertSame(0, $loan->transactions()->where('type', 'deposit')->count());

        $this->actingAs($teller)->getJson("/api/v1/teller/receipts/{$payment->id}")->assertOk()->assertJsonPath('data.receipt_number', $payment->receipt_number);
    }

    public function test_deposit_validation_overpayment_and_permissions(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin);
        $url = "/api/v1/teller/customers/{$loan->customer_id}/deposit";

        $this->postJson($url, ['p_method' => 'CASH'])->assertUnprocessable()->assertJsonValidationErrors('depost');
        $this->postJson($url, ['depost' => 100000, 'p_method' => 'CASH'])->assertCreated();
        $this->postJson($url, ['depost' => 30001, 'p_method' => 'CASH'])->assertUnprocessable()->assertJsonValidationErrors('depost');

        $officer = $this->employeeWithRole($admin, 'loan_officer');
        $this->actingAs($officer)->postJson($url, ['depost' => 1000, 'p_method' => 'CASH'])->assertForbidden();

        $otherBranch = Branch::factory()->create(['company_id' => $admin->company_id]);
        $foreignTeller = $this->employeeWithRole($admin, 'teller', $otherBranch->id);
        $this->actingAs($foreignTeller)->getJson("/api/v1/teller/customers/{$loan->customer_id}")->assertForbidden();
        $this->actingAs($foreignTeller)->postJson($url, ['depost' => 1000, 'p_method' => 'CASH'])->assertForbidden();
    }

    public function test_bank_deposit_verification_confirmation_allocates_principal_first_and_clears_holding_accounts(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin, penalty: 10000);
        $teller = $this->employeeWithRole($admin, 'teller');
        $finance = $this->employeeWithRole($admin, 'finance');
        $bank = BankAccount::create(['company_id' => $admin->company_id, 'name' => 'NMB']);

        $this->actingAs($teller)->postJson("/api/v1/teller/customers/{$loan->customer_id}/deposit", ['depost' => 105000, 'p_method' => 'CASH'])->assertCreated();
        $payment = Payment::sole();

        $this->actingAs($teller)->postJson('/api/v1/teller/bank-deposits', [
            'bank_account_id' => $bank->id, 'slip_number' => 'SLIP-0', 'amount' => 100000, 'deposit_date' => today()->toDateString(), 'payment_ids' => [$payment->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['amount' => 'The slip amount must equal the selected receipts (TZS 105,000.00).']);
        $this->assertSame(0, TellerDeposit::count());

        $this->actingAs($teller)->postJson('/api/v1/teller/bank-deposits', [
            'bank_account_id' => $bank->id, 'slip_number' => 'SLIP-1', 'amount' => 105000, 'deposit_date' => today()->toDateString(), 'payment_ids' => [$payment->id],
        ])->assertCreated();
        $deposit = TellerDeposit::sole();
        $this->assertSame(PaymentStatus::Deposited, $payment->fresh()->status);

        $this->actingAs($finance)->postJson("/api/v1/payments/reconciliation/{$deposit->id}/confirm")->assertUnprocessable();

        $this->actingAs($finance)->postJson("/api/v1/payments/reconciliation/{$deposit->id}/verify", ['statement_amount' => 100000, 'statement_reference' => 'NMB-778'])
            ->assertOk()->assertJsonPath('mismatch', true);
        $this->assertSame(TellerDeposit::STATUS_MISMATCH, $deposit->fresh()->status);

        $this->actingAs($finance)->postJson("/api/v1/payments/reconciliation/{$deposit->id}/reject", ['reason' => 'Slip amount differs from receipts'])->assertOk();
        $this->assertSame(PaymentStatus::PendingVerification, $payment->fresh()->status);

        $this->actingAs($teller)->postJson('/api/v1/teller/bank-deposits', [
            'bank_account_id' => $bank->id, 'slip_number' => 'SLIP-2', 'amount' => 105000, 'deposit_date' => today()->toDateString(), 'payment_ids' => [$payment->id],
        ])->assertCreated();
        $deposit = TellerDeposit::where('slip_number', 'SLIP-2')->sole();

        $this->actingAs($finance)->getJson('/api/v1/payments/reconciliation')->assertOk()->assertJsonPath('data.0.expected_amount', 105000);
        $this->actingAs($finance)->postJson("/api/v1/payments/reconciliation/{$deposit->id}/verify", ['statement_amount' => 105000, 'statement_reference' => 'NMB-779'])
            ->assertOk()->assertJsonPath('message', 'Deposit verified and posted: the loans are reduced and the money is in the bank');
        $this->actingAs($finance)->postJson("/api/v1/payments/reconciliation/{$deposit->id}/confirm")->assertUnprocessable();

        $transaction = $loan->transactions()->where('type', 'deposit')->sole();
        $this->assertEquals(100000, $transaction->principal);
        $this->assertEquals(5000, $transaction->penalty);
        $this->assertEquals(0, $transaction->interest);
        $this->assertSame(PaymentStatus::Confirmed, $payment->fresh()->status);
        $this->assertSame(TellerDeposit::STATUS_CONFIRMED, $deposit->fresh()->status);

        $this->assertSame(0.0, $this->balance($admin, Account::TellerCash, $admin->branch_id, employeeId: $teller->id));
        $this->assertSame(0.0, $this->balance($admin, Account::Suspense, $admin->branch_id));
        $this->assertSame(0.0, $this->balance($admin, Account::Bank, bankAccountId: $bank->id));
        $this->assertSame(0.0, $this->balance($admin, Account::LoanReceivable, $admin->branch_id));
        $this->assertSame(5000.0, $this->balance($admin, Account::PenaltyIncome, $admin->branch_id));
        $this->assertTrue(SmsLog::where('customer_id', $loan->customer_id)->where('message', 'like', '%yamethibitishwa%')->exists());
        $this->assertTrue(AuditLog::where('action', 'TellerDeposit.updated')->exists());
    }

    public function test_the_teller_edits_a_mismatched_slip_and_finance_verifies_it_again(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin);
        $teller = $this->employeeWithRole($admin, 'teller');
        $otherTeller = $this->employeeWithRole($admin, 'teller');
        $finance = $this->employeeWithRole($admin, 'finance');
        $nmb = BankAccount::create(['company_id' => $admin->company_id, 'name' => 'NMB']);
        $crdb = BankAccount::create(['company_id' => $admin->company_id, 'name' => 'CRDB']);
        $url = "/api/v1/teller/customers/{$loan->customer_id}/deposit";
        $first = $this->actingAs($teller)->postJson($url, ['depost' => 1000, 'p_method' => 'CASH'])->assertCreated()->json('data.id');
        $second = $this->actingAs($teller)->postJson($url, ['depost' => 1000, 'p_method' => 'CASH'])->assertCreated()->json('data.id');

        $slip = ['bank_account_id' => $crdb->id, 'slip_number' => 'S-2000', 'amount' => 2000, 'deposit_date' => today()->toDateString(), 'payment_ids' => [$first, $second]];
        $this->actingAs($teller)->postJson('/api/v1/teller/bank-deposits', $slip)->assertCreated();
        $deposit = TellerDeposit::sole();

        $this->actingAs($teller)->putJson("/api/v1/teller/bank-deposits/{$deposit->id}", $slip)->assertUnprocessable()->assertJsonValidationErrors('slip_number');

        // The bank shows only 1,000: MISMATCH, nothing posted.
        $this->actingAs($finance)->postJson("/api/v1/payments/reconciliation/{$deposit->id}/verify", ['statement_amount' => 1000, 'statement_reference' => 'ST-1'])->assertOk()->assertJsonPath('mismatch', true);
        $this->actingAs($teller)->getJson('/api/v1/teller/bank-deposits')->assertOk()->assertJsonPath('data.0.can_edit', true)->assertJsonPath('data.0.difference', -1000);
        $this->actingAs($otherTeller)->putJson("/api/v1/teller/bank-deposits/{$deposit->id}", $slip)->assertForbidden();

        // Only one receipt was really banked, on NMB: the other goes back to be banked later.
        $fixed = ['bank_account_id' => $nmb->id, 'slip_number' => 'S-1000', 'amount' => 1000, 'deposit_date' => today()->toDateString(), 'payment_ids' => [$first]];
        $this->actingAs($teller)->putJson("/api/v1/teller/bank-deposits/{$deposit->id}", $fixed)->assertOk()->assertJsonPath('data.status', TellerDeposit::STATUS_PENDING);
        $deposit->refresh();
        $this->assertSame(['S-1000', $nmb->id, null, null], [$deposit->slip_number, (int) $deposit->bank_account_id, $deposit->statement_amount, $deposit->verified_by]);
        $this->assertSame([PaymentStatus::Deposited, PaymentStatus::PendingVerification], [Payment::find($first)->status, Payment::find($second)->status]);
        $this->assertNull(Payment::find($second)->teller_deposit_id);

        $this->actingAs($finance)->postJson("/api/v1/payments/reconciliation/{$deposit->id}/verify", ['statement_amount' => 1000, 'statement_reference' => 'ST-2'])->assertOk();
        $this->assertSame(TellerDeposit::STATUS_CONFIRMED, $deposit->fresh()->status);
        $this->assertSame(1, $loan->transactions()->where('type', 'deposit')->count());
    }

    public function test_bank_and_mno_teller_payments_follow_the_cash_process(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin);
        $teller = $this->employeeWithRole($admin, 'teller');
        $finance = $this->employeeWithRole($admin, 'finance');
        $bank = BankAccount::create(['company_id' => $admin->company_id, 'name' => 'NMB']);
        PaymentProvider::create(['company_id' => $admin->company_id, 'channel' => 'MNO', 'name' => 'M-Pesa']);
        $url = "/api/v1/teller/customers/{$loan->customer_id}/deposit";

        $this->actingAs($teller)->postJson($url, ['depost' => 20000, 'p_method' => 'MNO'])->assertUnprocessable()->assertJsonValidationErrors('provider');
        $this->actingAs($teller)->postJson($url, ['depost' => 20000, 'p_method' => 'BANK', 'provider' => 'M-Pesa'])->assertUnprocessable()->assertJsonValidationErrors('provider');
        $this->actingAs($teller)->postJson($url, ['depost' => 20000, 'p_method' => 'MNO', 'provider' => 'M-Pesa'])->assertCreated()
            ->assertJsonPath('data.status', PaymentStatus::PendingVerification->value)->assertJsonPath('data.provider', 'M-Pesa');
        $payment = Payment::sole();
        $this->assertSame(0, $loan->transactions()->where('type', 'deposit')->count(), 'nothing reduces the loan before Finance verifies the slip');
        $this->actingAs($finance)->getJson('/api/v1/payments/branch-receipts?status=all')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($teller)->postJson('/api/v1/teller/bank-deposits', [
            'bank_account_id' => $bank->id, 'slip_number' => 'SLIP-MNO', 'amount' => 20000, 'deposit_date' => today()->toDateString(), 'payment_ids' => [$payment->id],
        ])->assertCreated();
        $deposit = TellerDeposit::sole();
        $this->actingAs($finance)->postJson("/api/v1/payments/reconciliation/{$deposit->id}/verify", ['statement_amount' => 20000, 'statement_reference' => 'NMB-MNO'])->assertOk();

        $this->assertSame(PaymentStatus::Confirmed, $payment->fresh()->status);
        $this->assertSame(1, $loan->transactions()->where('type', 'deposit')->count(), 'the loan is reduced once the slip is verified');
    }

    public function test_a_deposit_left_verified_by_the_old_two_step_flow_is_still_posted_by_confirm(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin);
        $teller = $this->employeeWithRole($admin, 'teller');
        $finance = $this->employeeWithRole($admin, 'finance');
        $bank = BankAccount::create(['company_id' => $admin->company_id, 'name' => 'NMB']);

        $this->actingAs($teller)->postJson("/api/v1/teller/customers/{$loan->customer_id}/deposit", ['depost' => 50000, 'p_method' => 'CASH'])->assertCreated();
        $this->actingAs($teller)->postJson('/api/v1/teller/bank-deposits', [
            'bank_account_id' => $bank->id, 'slip_number' => 'SLIP-OLD', 'amount' => 50000, 'deposit_date' => today()->toDateString(), 'payment_ids' => [Payment::sole()->id],
        ])->assertCreated();
        $deposit = TellerDeposit::sole();
        app(PaymentService::class)->verifyDeposit($deposit, 50000, 'NMB-OLD', $finance);
        $this->assertSame(TellerDeposit::STATUS_VERIFIED, $deposit->fresh()->status);

        $this->actingAs($finance)->postJson("/api/v1/payments/reconciliation/{$deposit->id}/confirm")->assertOk();
        $this->assertSame(PaymentStatus::Confirmed, Payment::sole()->fresh()->status);
        $this->assertSame(1, $loan->transactions()->where('type', 'deposit')->count(), 'the loan is reduced');
    }

    public function test_finance_rejects_pending_cash_with_ledger_reversal(): void
    {
        $admin = $this->signInAdmin();
        $loan = $this->activeLoan($admin);
        $this->postJson("/api/v1/teller/customers/{$loan->customer_id}/deposit", ['depost' => 20000, 'p_method' => 'CASH'])->assertCreated();
        $payment = Payment::sole();

        $this->postJson("/api/v1/payments/cash/{$payment->id}/reject", [])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $teller = $this->employeeWithRole($admin, 'teller');
        $this->actingAs($teller)->postJson("/api/v1/payments/cash/{$payment->id}/reject", ['reason' => 'x'])->assertForbidden();

        $finance = $this->employeeWithRole($admin, 'finance');
        $this->actingAs($finance)->getJson('/api/v1/payments/cash?status=pending_verification')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($finance)->postJson("/api/v1/payments/cash/{$payment->id}/reject", ['reason' => 'Wrong customer'])->assertOk();

        $this->assertSame(PaymentStatus::Rejected, $payment->fresh()->status);
        $this->assertSame(0.0, $this->balance($admin, Account::Suspense, $admin->branch_id));
        $this->assertTrue($payment->journalEntry->reversal()->exists());
        $this->actingAs($finance)->postJson("/api/v1/payments/cash/{$payment->id}/reject", ['reason' => 'again'])->assertUnprocessable();
    }

    public function test_cash_verification_list_is_branch_scoped(): void
    {
        $admin = $this->signInAdmin();
        $otherBranch = Branch::factory()->create(['company_id' => $admin->company_id]);
        $mine = $this->activeLoan($admin);
        $theirs = $this->activeLoan($admin, branch: $otherBranch);
        $this->postJson("/api/v1/teller/customers/{$mine->customer_id}/deposit", ['depost' => 1000, 'p_method' => 'CASH'])->assertCreated();
        $this->postJson("/api/v1/teller/customers/{$theirs->customer_id}/deposit", ['depost' => 2000, 'p_method' => 'CASH'])->assertCreated();

        $this->getJson('/api/v1/payments/cash')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/payments/cash?branch_id={$otherBranch->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', 2000);

        $teller = $this->employeeWithRole($admin, 'teller');
        $this->actingAs($teller)->getJson('/api/v1/teller/cash')->assertOk()->assertJsonCount(0, 'data');
        $theirPayment = Payment::where('branch_id', $otherBranch->id)->sole();
        $bank = BankAccount::create(['company_id' => $admin->company_id, 'name' => 'CRDB']);
        $this->actingAs($teller)->postJson('/api/v1/teller/bank-deposits', [
            'bank_account_id' => $bank->id, 'slip_number' => 'S', 'amount' => 2000, 'deposit_date' => today()->toDateString(), 'payment_ids' => [$theirPayment->id],
        ])->assertForbidden();
    }
}

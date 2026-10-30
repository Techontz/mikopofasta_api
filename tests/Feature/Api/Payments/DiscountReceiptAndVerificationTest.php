<?php

namespace Tests\Feature\Api\Payments;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Models\BankAccount;
use App\Models\Employee;
use App\Models\LoanDiscount;
use App\Models\LoanTransaction;
use App\Models\Payment;
use App\Models\TellerDeposit;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Enhancement §6 (loan discount / waiver), §8.1 (Cash Verification tabs), §8.3–8.4 (deposit receipt, View Receipt).
 */
class DiscountReceiptAndVerificationTest extends TestCase
{
    use InteractsWithRepayments;
    use RefreshDatabase;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
    }

    public function test_a_discount_changes_nothing_until_another_user_approves_it(): void
    {
        $loan = $this->activeLoan($this->admin);
        $manager = $this->employeeWithRole($this->admin, 'branch_manager');
        $finance = $this->employeeWithRole($this->admin, 'finance');

        $id = $this->actingAs($manager)->postJson("/api/v1/loans/{$loan->id}/discounts", ['amount' => 10000, 'actual_payment' => 120000, 'reason' => 'Settlement agreed'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.original_debt', 130000)->json('data.id');
        $this->assertSame(130000.0, app(LoanService::class)->outstanding($loan->fresh())['total']);

        $this->actingAs($manager)->postJson("/api/v1/loans/discounts/{$id}/approve")->assertForbidden();
        $this->actingAs($this->employeeWithRole($this->admin, 'teller'))->postJson("/api/v1/loans/discounts/{$id}/approve")->assertForbidden();

        $this->actingAs($finance)->postJson("/api/v1/loans/discounts/{$id}/approve", ['approved_amount' => 8000])->assertOk()->assertJsonPath('data.approved_amount', 8000);
        $this->assertSame(122000.0, app(LoanService::class)->outstanding($loan->fresh())['total']);
        $this->actingAs($finance)->postJson("/api/v1/loans/discounts/{$id}/approve")->assertUnprocessable();
        $this->assertSame(1, LoanTransaction::where('type', LoanTransaction::TYPE_DISCOUNT)->count(), 'Applied once.');
    }

    public function test_a_discount_is_never_cash_and_the_principal_part_leaves_the_receivable(): void
    {
        $loan = $this->activeLoan($this->admin);
        app(LoanService::class)->deposit($loan, 120000, CarbonImmutable::today(), 'CASH', $this->admin);
        $receivable = $this->balance($this->admin, Account::LoanReceivable, $this->admin->branch_id);
        $received = $this->getJson('/api/v1/reports/received')->assertOk()->json('data.totals');

        $discount = $this->requestAndApprove($loan->id, 10000);

        $this->assertSame(LoanStatus::Closed, $loan->fresh()->status, 'Outstanding 130,000: 120,000 paid + 10,000 waived settles the loan.');
        $this->assertEqualsWithDelta((float) $discount->principal, $this->balance($this->admin, Account::LoanDiscountExpense, $this->admin->branch_id), 0.01);
        $this->assertEqualsWithDelta($receivable - (float) $discount->principal, $this->balance($this->admin, Account::LoanReceivable, $this->admin->branch_id), 0.01);
        $this->assertEquals($received, $this->getJson('/api/v1/reports/received')->assertOk()->json('data.totals'), 'Cash received is unchanged.');
        $this->assertSame(120000.0, (float) $loan->fresh()->transactions()->where('type', 'deposit')->sum('amount'));
    }

    public function test_a_discount_cannot_exceed_the_outstanding_principal_interest_and_insurance(): void
    {
        $loan = $this->activeLoan($this->admin, penalty: 5000);

        $this->postJson("/api/v1/loans/{$loan->id}/discounts", ['amount' => 130001, 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson("/api/v1/loans/{$loan->id}/discounts", ['amount' => 1000, 'reason' => 'x'])->assertCreated();
        $this->postJson("/api/v1/loans/{$loan->id}/discounts", ['amount' => 1000, 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('loan');
        $this->assertSame(1, LoanDiscount::count());
    }

    public function test_a_bank_deposit_needs_no_slip_number_and_no_receipt_and_says_when_none_is_attached(): void
    {
        [$teller, $bank, $payment] = $this->tellerReceipt();

        $id = $this->actingAs($teller)->postJson('/api/v1/teller/bank-deposits', ['bank_account_id' => $bank->id, 'amount' => 50000, 'deposit_date' => today()->toDateString(), 'payment_ids' => [$payment->id]])
            ->assertCreated()->assertJsonPath('data.has_receipt', false)->json('data.id');

        $this->actingAs($this->admin)->getJson("/api/v1/payments/reconciliation/{$id}/receipt")->assertNotFound()->assertJsonPath('message', 'No receipt was uploaded for this deposit.');
    }

    public function test_an_uploaded_receipt_opens_from_bank_reconciliation(): void
    {
        Storage::fake('local');
        [$teller, $bank, $payment] = $this->tellerReceipt();

        $response = $this->actingAs($teller)->post('/api/v1/teller/bank-deposits', [
            'bank_account_id' => $bank->id, 'amount' => 50000, 'deposit_date' => today()->toDateString(), 'payment_ids' => [$payment->id],
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.has_receipt', true);
        $deposit = TellerDeposit::findOrFail($response->json('data.id'));
        Storage::disk('local')->assertExists($deposit->receipt_file);

        $this->actingAs($this->admin)->get("/api/v1/payments/reconciliation/{$deposit->id}/receipt")->assertOk()->assertHeader('content-disposition');
        $this->actingAs($this->admin)->getJson('/api/v1/payments/reconciliation?branch_id=all')->assertOk()
            ->assertJsonPath('data.0.has_receipt', true)->assertJsonPath('summary.pending.count', 1);

        $this->actingAs($teller)->post('/api/v1/teller/bank-deposits', [
            'bank_account_id' => $bank->id, 'amount' => 1, 'deposit_date' => today()->toDateString(), 'payment_ids' => [$payment->id],
            'receipt' => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('receipt');
    }

    public function test_pending_and_deposited_receipts_stay_listed_after_the_day_changes(): void
    {
        [, , $payment] = $this->tellerReceipt();
        $this->travel(3)->days();
        $today = today()->toDateString();

        $this->getJson("/api/v1/payments/cash?status=pending_verification&from={$today}&to={$today}")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('summary.pending_verification.count', 1);

        Payment::whereKey($payment->id)->update(['status' => 'confirmed', 'verified_at' => now()->subDay()]);
        $this->getJson("/api/v1/payments/cash?status=confirmed&from={$today}&to={$today}")->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('summary.confirmed.count', 0);
        $yesterday = today()->subDay()->toDateString();
        $this->getJson("/api/v1/payments/cash?status=confirmed&from={$yesterday}&to={$yesterday}")->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * @return array{0: Employee, 1: BankAccount, 2: Payment}
     */
    private function tellerReceipt(): array
    {
        $loan = $this->activeLoan($this->admin);
        $teller = $this->employeeWithRole($this->admin, 'teller');
        $this->actingAs($teller)->postJson("/api/v1/teller/customers/{$loan->customer_id}/deposit", ['depost' => 50000, 'p_method' => 'CASH'])->assertCreated();
        $this->actingAs($this->admin);

        return [$teller, BankAccount::create(['company_id' => $this->admin->company_id, 'name' => 'NMB']), Payment::sole()];
    }

    private function requestAndApprove(int $loanId, float $amount): LoanDiscount
    {
        $manager = $this->employeeWithRole($this->admin, 'branch_manager');
        $id = $this->actingAs($manager)->postJson("/api/v1/loans/{$loanId}/discounts", ['amount' => $amount, 'reason' => 'Settlement'])->assertCreated()->json('data.id');
        $this->actingAs($this->admin)->postJson("/api/v1/loans/discounts/{$id}/approve")->assertOk();

        return LoanDiscount::findOrFail($id);
    }
}

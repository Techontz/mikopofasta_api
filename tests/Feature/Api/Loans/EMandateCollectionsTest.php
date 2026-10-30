<?php

namespace Tests\Feature\Api\Loans;

use App\Enums\PaymentStatus;
use App\Integrations\Payments\PaymentNotification;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanMandate;
use App\Models\MandateCollection;
use App\Models\Payment;
use App\Services\LoanService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Payments\InteractsWithRepayments;
use Tests\TestCase;

/**
 * Enhancement §4: e-mandate collections, Full / Partial by allocation, idempotency, payment restrictions, reports.
 */
class EMandateCollectionsTest extends TestCase
{
    use InteractsWithRepayments, RefreshDatabase;

    private Employee $admin;

    private Loan $loan;

    private LoanMandate $mandate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->loan = $this->activeLoan($this->admin);
        $this->loan->schedules()->delete();
        $this->loan->schedules()->create(['due_date' => today()->subDays(30)->toDateString(), 'amount' => 40000]);
        $this->loan->schedules()->create(['due_date' => today()->toDateString(), 'amount' => 90000]);
        $this->mandate = $this->mandateFor($this->loan, 'EM-0001');
    }

    public function test_a_collection_covering_arrears_and_the_current_instalment_is_full_and_allocated_once(): void
    {
        $this->import("EM-0001,PAY-1,130000,SUCCESS\n")->assertCreated()->assertJsonPath('data.recorded', 1);

        $collection = MandateCollection::sole();
        $this->assertSame([MandateCollection::FULL, 130000.0, 130000.0], [$collection->payment_status, (float) $collection->expected_amount, (float) $collection->allocated_amount]);
        $this->assertSame(0.0, app(LoanService::class)->outstanding($this->loan->fresh())['total']);

        $this->import("EM-0001,PAY-1,130000,SUCCESS\n")->assertCreated()->assertJsonPath('data.recorded', 0)->assertJsonPath('data.repeated', 1);
        $this->assertSame(1, MandateCollection::count());
        $this->assertSame(1, Payment::whereNull('parent_id')->count(), 'A repeated bank file never allocates twice.');
    }

    public function test_a_success_status_for_less_than_what_was_due_is_partial(): void
    {
        $this->import("EM-0001,PAY-2,100000,SUCCESS\n");

        $collection = MandateCollection::sole();
        $this->assertSame(MandateCollection::PARTIAL, $collection->payment_status, 'SUCCESS from the bank does not make it full.');
        $this->assertSame(130000.0, (float) $collection->expected_amount);
    }

    public function test_failed_and_unmatched_debits_post_nothing(): void
    {
        $this->import("EM-0001,PAY-3,130000,INSUFFICIENT_FUNDS\nEM-9999,PAY-4,5000,SUCCESS\n");

        $this->assertSame([MandateCollection::FAILED, MandateCollection::UNMATCHED], MandateCollection::orderBy('id')->pluck('payment_status')->all());
        $this->assertSame(0, Payment::count());
        $this->assertSame(130000.0, app(LoanService::class)->outstanding($this->loan->fresh())['total']);
    }

    public function test_customer_payment_id_payments_to_an_e_mandate_loan_are_held_for_finance(): void
    {
        $result = app(PaymentService::class)->receive(new PaymentNotification('TX-1', 50000, 'mpesa', $this->loan->reference_number ?? $this->loan->loan_number));

        $this->assertSame('SUSPENSE', $result['status']);
        $this->assertSame(PaymentStatus::Unallocated, Payment::sole()->status);
        $this->assertStringContainsString('E-Mandate', (string) Payment::sole()->note);
        $this->assertSame(130000.0, app(LoanService::class)->outstanding($this->loan->fresh())['total']);
    }

    public function test_a_cancellation_is_only_confirmed_with_the_bank_reference(): void
    {
        $this->postJson("/api/v1/loans/mandates/{$this->mandate->id}/confirm-cancellation", ['reference' => 'X'])->assertUnprocessable();

        $this->mandate->forceFill(['cancellation_status' => LoanMandate::CANCELLATION_REQUESTED, 'cancellation_requested_at' => now()])->save();
        $this->postJson("/api/v1/loans/mandates/{$this->mandate->id}/confirm-cancellation", [])->assertUnprocessable();
        $this->postJson("/api/v1/loans/mandates/{$this->mandate->id}/confirm-cancellation", ['reference' => 'BANK-CXL-77'])->assertOk()->assertJsonPath('data.cancellation_status', 'confirmed');

        $this->assertSame('BANK-CXL-77', $this->mandate->fresh()->cancellation_reference);
        $this->assertSame(LoanMandate::ACTIVE, $this->mandate->fresh()->status, 'The mandate record and its history are kept.');
    }

    public function test_the_provider_webhook_refuses_until_it_is_configured(): void
    {
        $this->postJson('/api/webhooks/bank-mandate/collections', ['txn' => 'A'])->assertStatus(503);
        $this->assertSame(0, MandateCollection::count());
    }

    public function test_the_report_shows_mandates_and_this_months_collections_with_matching_totals(): void
    {
        $this->mandateFor($this->activeLoan($this->admin), 'EM-0002', LoanMandate::PENDING_OTP);
        $this->import("EM-0001,PAY-5,100000,SUCCESS\n");

        $data = $this->getJson('/api/v1/loans/mandates/report')->assertOk()->json('data');

        $this->assertSame([2, 1, 1, 0], [$data['cards']['total_mandates'], $data['cards']['approved_mandates'], $data['cards']['pending_mandates'], $data['cards']['failed_mandates']]);
        $this->assertCount(1, $data['paid_rows']);
        $this->assertEqualsWithDelta(array_sum(array_column($data['paid_rows'], 'allocated_amount')), $data['cards']['paid_this_period'], 0.01);
        $this->assertSame(['provider', 'channel', 'mandate_type', 'mandate_reference', 'payment_reference', 'raw_status', 'payment_status', 'expected_amount', 'paid_amount', 'collection_date', 'remarks'], array_values(array_intersect(array_keys($data['paid_rows'][0]), ['provider', 'channel', 'mandate_type', 'mandate_reference', 'payment_reference', 'raw_status', 'payment_status', 'expected_amount', 'paid_amount', 'collection_date', 'remarks'])));
        $this->assertCount(1, $data['batches']);
    }

    private function import(string $rows): TestResponse
    {
        $file = UploadedFile::fake()->createWithContent('collections.csv', "mandate_reference,payment_reference,amount,status\n".$rows);

        return $this->post('/api/v1/loans/mandates/collections', ['provider' => 'CRDB', 'channel' => 'BANK', 'collection_date' => today()->toDateString(), 'file' => $file], ['Accept' => 'application/json']);
    }

    private function mandateFor(Loan $loan, string $reference, string $status = LoanMandate::ACTIVE): LoanMandate
    {
        return LoanMandate::create([
            'company_id' => $loan->company_id, 'loan_id' => $loan->id, 'employee_id' => $this->admin->id,
            'bank_name' => 'CRDB', 'account_number' => '0150'.random_int(10000, 99999), 'account_name' => 'Customer',
            'mandate_reference' => $reference, 'status' => $status, 'provider' => 'CRDB', 'channel' => 'BANK', 'mandate_type' => 'MONTHLY',
            'activated_at' => $status === LoanMandate::ACTIVE ? now() : null,
        ]);
    }
}

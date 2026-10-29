<?php

namespace Tests\Feature\Api\Customers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerDuplicateReview;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Customers\Concerns\BuildsCustomerModule;
use Tests\TestCase;

/**
 * Enhancement §2.1: registrations are checked against every branch's customers, whatever the registering employee may see.
 */
class DuplicateCustomerPreventionTest extends TestCase
{
    use BuildsCustomerModule, RefreshDatabase;

    private Employee $admin;

    private Employee $officer;

    private Customer $existing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->seedCustomerModule($this->admin);
        $otherBranch = Branch::factory()->create(['company_id' => $this->admin->company_id]);
        $this->existing = Customer::factory()->create([
            'branch_id' => $otherBranch->id,
            'first_name' => 'Rehema',
            'middle_name' => 'Ally',
            'last_name' => 'Mussa',
            'phone' => '0754123456',
            'nida_number' => '19850101-12345-00001-12',
            'account_number' => '0150-2233-4455',
            'check_number' => 'CHK 778899',
        ]);
        $this->officer = $this->employeeWithRole($this->admin, 'loan_officer');
    }

    public function test_a_nida_number_registered_in_another_branch_is_refused_without_naming_the_customer(): void
    {
        $response = $this->actingAs($this->officer)->postJson('/api/v1/customers', $this->payload(['nidaNumber' => '19850101123450000112']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nidaNumber' => 'A customer with this NIDA number is already registered.']);

        $this->assertStringNotContainsString('Rehema', $response->getContent());
        $this->assertStringNotContainsString($this->existing->customer_number, $response->getContent());
        $this->actingAs($this->officer)->getJson("/api/v1/customers/{$this->existing->id}")->assertNotFound();
        $this->assertSame(1, Customer::count());
    }

    public function test_a_phone_number_in_another_format_is_refused(): void
    {
        $this->actingAs($this->officer)->postJson('/api/v1/customers', $this->payload(['phone' => '+255 754 123 456']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => 'A customer with this phone number is already registered.']);
    }

    public function test_a_shared_bank_account_holds_the_registration_for_verification(): void
    {
        $response = $this->actingAs($this->officer)->postJson('/api/v1/customers', $this->payload([
            'paymentMethod' => 'bank',
            'bankId' => $this->ids['crdb'],
            'bankDetails' => ['bankName' => 'CRDB Bank', 'accountNumber' => '015022334455', 'accountName' => 'Asha Hamisi'],
        ]))->assertAccepted()->assertJsonPath('data.status', 'pending');

        $this->assertStringNotContainsString('Rehema', $response->getContent());
        $this->assertSame(1, Customer::count());
        $review = CustomerDuplicateReview::findOrFail($response->json('data.duplicate_review_id'));
        $this->assertEquals([['customer_id' => $this->existing->id, 'reasons' => ['bank_account']]], $review->matches);
        $this->assertSame($this->officer->id, $review->requested_by);
    }

    public function test_the_same_three_names_hold_the_registration_and_a_check_number_match_is_reported(): void
    {
        $response = $this->actingAs($this->officer)->postJson('/api/v1/customers', $this->payload([
            'firstName' => 'REHEMA', 'middleName' => 'ally', 'lastName' => ' Mussa ', 'checkNumber' => 'chk-778899',
        ]))->assertAccepted();

        $review = CustomerDuplicateReview::findOrFail($response->json('data.duplicate_review_id'));
        $this->assertEquals([['customer_id' => $this->existing->id, 'reasons' => ['check_number', 'full_name']]], $review->matches);
    }

    public function test_only_a_kyc_approver_sees_the_matches_and_can_release_the_registration(): void
    {
        $reviewId = $this->holdByName();

        $this->actingAs($this->officer)->getJson('/api/v1/customers/duplicate-reviews')->assertForbidden();
        $this->actingAs($this->officer)->getJson("/api/v1/customers/duplicate-reviews/{$reviewId}")->assertForbidden();
        $this->actingAs($this->officer)->postJson("/api/v1/customers/duplicate-reviews/{$reviewId}/release", ['reason' => 'x'])->assertForbidden();

        $crm = $this->employeeWithRole($this->admin, 'crm');
        $this->actingAs($crm)->getJson('/api/v1/customers/duplicate-reviews')->assertOk()->assertJsonPath('data.0.id', $reviewId);
        $this->actingAs($crm)->getJson("/api/v1/customers/duplicate-reviews/{$reviewId}")->assertOk()
            ->assertJsonPath('data.matches.0.customerId', $this->existing->id)
            ->assertJsonPath('data.matches.0.reasons', ['full_name']);

        $this->actingAs($crm)->postJson("/api/v1/customers/duplicate-reviews/{$reviewId}/release", [])->assertUnprocessable();
        $created = $this->actingAs($crm)->postJson("/api/v1/customers/duplicate-reviews/{$reviewId}/release", ['reason' => 'Different person: other date of birth and NIDA checked.'])
            ->assertCreated();

        $customer = Customer::findOrFail($created->json('data.id'));
        $this->assertSame($this->officer->id, (int) $customer->created_by);
        $this->assertSame('pending', $customer->approval_status);
        $review = CustomerDuplicateReview::findOrFail($reviewId);
        $this->assertSame([CustomerDuplicateReview::STATUS_RELEASED, $crm->id, $customer->id], [$review->status, $review->decided_by, $review->customer_id]);
        $this->assertTrue(AuditLog::where('action', 'Customer.duplicate_review_released')->where('auditable_id', $customer->id)->where('employee_id', $crm->id)->exists());

        $this->actingAs($crm)->postJson("/api/v1/customers/duplicate-reviews/{$reviewId}/release", ['reason' => 'again'])->assertStatus(409);
    }

    public function test_the_employee_who_submitted_the_registration_cannot_decide_it(): void
    {
        $reviewId = $this->holdByName();
        $crm = $this->employeeWithRole($this->admin, 'crm');
        CustomerDuplicateReview::whereKey($reviewId)->update(['requested_by' => $crm->id]);

        $this->actingAs($crm)->postJson("/api/v1/customers/duplicate-reviews/{$reviewId}/release", ['reason' => 'Mine'])->assertForbidden();
        $this->actingAs($crm)->postJson("/api/v1/customers/duplicate-reviews/{$reviewId}/reject", ['reason' => 'Mine'])->assertForbidden();
        $this->assertSame(CustomerDuplicateReview::STATUS_PENDING, CustomerDuplicateReview::findOrFail($reviewId)->status);
    }

    public function test_a_rejected_duplicate_cannot_be_registered_again(): void
    {
        $reviewId = $this->holdByName();
        $crm = $this->employeeWithRole($this->admin, 'crm');

        $this->actingAs($crm)->postJson("/api/v1/customers/duplicate-reviews/{$reviewId}/reject", ['reason' => 'Same person: NIDA photo matches.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->actingAs($this->officer)->postJson('/api/v1/customers', $this->payload(['firstName' => 'Rehema', 'middleName' => 'Ally', 'lastName' => 'Mussa']))
            ->assertUnprocessable()->assertJsonValidationErrors(['customer']);
        $this->assertSame(1, CustomerDuplicateReview::count());
        $this->assertSame(1, Customer::count());
    }

    public function test_resubmitting_a_held_registration_updates_the_same_review(): void
    {
        $first = $this->holdByName();
        $second = $this->holdByName();

        $this->assertSame($first, $second);
        $this->assertSame(1, CustomerDuplicateReview::count());
    }

    public function test_an_update_cannot_take_another_customers_bank_account(): void
    {
        $customer = Customer::factory()->create(['branch_id' => $this->admin->branch_id, 'phone' => '0713999888']);

        $this->actingAs($this->admin)->putJson("/api/v1/customers/{$customer->id}", [
            'paymentMethod' => 'bank',
            'bankId' => $this->ids['crdb'],
            'bankDetails' => ['bankName' => 'CRDB Bank', 'accountNumber' => '0150 2233 4455', 'accountName' => 'Someone'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['bankDetails.accountNumber']);

        $this->assertNull($customer->refresh()->account_number);
    }

    public function test_a_customer_with_no_identifier_in_common_registers_normally(): void
    {
        $this->actingAs($this->officer)->postJson('/api/v1/customers', $this->payload())->assertCreated();

        $this->assertSame(0, CustomerDuplicateReview::count());
    }

    private function holdByName(): int
    {
        return (int) $this->actingAs($this->officer)->postJson('/api/v1/customers', $this->payload(['firstName' => 'Rehema', 'middleName' => 'Ally', 'lastName' => 'Mussa']))
            ->assertAccepted()->json('data.duplicate_review_id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $this->registrationPayload($this->admin, array_replace(['employeeId' => $this->officer->id, 'phone' => '0765000111', 'idNumber' => '19950505123450000999'], $overrides));
    }
}

<?php

namespace Tests\Feature\Api\Loans;

use App\Enums\Duration;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\LoanCategory;
use App\Services\CustomerEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A newly registered customer cannot be applied for until the registration (KYC) is approved: a complete KYC is not
 * enough while the registration waits for approval or was returned.
 */
class LoanRegistrationApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_loan_is_refused_until_the_registration_is_approved(): void
    {
        $admin = $this->signInAdmin();
        $type = CustomerCategory::factory()->create(['company_id' => $admin->company_id]);
        $category = LoanCategory::factory()->forCustomerType($type)->create(['insurance' => 0, 'duration' => Duration::Monthly]);
        $category->branches()->attach($admin->branch_id);
        $customer = Customer::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'customer_category_id' => $type->id, 'kyc_status' => 'completed', 'approval_status' => 'pending']);
        $form = ['customer_id' => $customer->id, 'category_id' => $category->id, 'how_loan' => 100000, 'session' => 1, 'rate' => 'SIMPLE', 'fee_status' => 'YES', 'reason' => 'BIASHARA'];

        $this->getJson("/api/v1/customers/{$customer->id}/eligibility")
            ->assertJsonPath('data.can_apply', false)
            ->assertJsonPath('data.registration_approved', false)
            ->assertJsonPath('data.reasons', [CustomerEligibility::REGISTRATION_PENDING]);
        $this->postJson(route('api.v1.loans.store'), $form)->assertUnprocessable()->assertJsonPath('errors.customer_id.0', CustomerEligibility::REGISTRATION_PENDING);

        $customer->update(['approval_status' => 'rejected']);
        $this->postJson(route('api.v1.loans.store'), $form)->assertUnprocessable()->assertJsonPath('errors.customer_id.0', CustomerEligibility::REGISTRATION_REJECTED);

        $customer->update(['approval_status' => 'approved']);
        $this->getJson("/api/v1/customers/{$customer->id}/eligibility")->assertJsonPath('data.can_apply', true);
        $this->postJson(route('api.v1.loans.store'), $form)->assertCreated();
    }
}

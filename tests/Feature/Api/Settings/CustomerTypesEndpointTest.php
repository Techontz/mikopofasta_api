<?php

namespace Tests\Feature\Api\Settings;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\LoanCategory;
use App\Services\CustomerEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Customers\Concerns\BuildsCustomerModule;
use Tests\TestCase;

/**
 * "Customer Type" is the UI name of customer_categories: GET /customer-types lists the selectable types, registration
 * accepts only active types of the company, and messages say "customer type".
 */
class CustomerTypesEndpointTest extends TestCase
{
    use BuildsCustomerModule, RefreshDatabase;

    private const FIVE = ['Mtumishi wa Umma', 'Sekta Binafsi', 'Mjasiriamali/Mfanyabiashara', 'Mwanafunzi wa Chuo', 'Mstaafu (Umma)'];

    private function extraType(int $companyId, string $code, array $attributes = []): CustomerCategory
    {
        return CustomerCategory::create($attributes + [
            'company_id' => $companyId, 'key' => strtolower($code), 'code' => $code, 'name' => ucfirst(strtolower($code)),
            'required_documents' => [], 'form_schema' => [], 'sort_order' => 0,
        ]);
    }

    public function test_customer_types_lists_exactly_the_five_active_types_in_order(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $this->extraType($admin->company_id, 'INACTIVE_TYPE', ['is_active' => false]);
        $this->extraType($admin->company_id, 'DELETED_TYPE')->delete();
        $this->extraType(Company::factory()->create()->id, 'FOREIGN_TYPE');

        $this->actingAs($this->employeeWithRole($admin, 'loan_officer'));

        $response = $this->getJson('/api/v1/customer-types')->assertOk()->assertJsonCount(5, 'data');
        $this->assertSame(self::FIVE, array_column($response->json('data'), 'name'));
        $this->assertSame(['WATUMISHI_WA_UMMA', 'SEKTA_BINAFSI', 'WAJASIRIAMALI', 'MWANAFUNZI_CHUO', 'MSTAAFU_UMMA'], array_column($response->json('data'), 'code'));
        $response->assertJsonStructure(['data' => [['id', 'code', 'name', 'formTitle', 'sector', 'isActive', 'sortOrder', 'dynamicFormSchema']]]);
        foreach ($response->json('data') as $type) {
            $this->assertEmpty(array_intersect(array_keys($type), ['minLoanAmount', 'maxLoanAmount', 'loanCategories']), 'Customer types carry no loan configuration.');
        }

        // Same source as the Settings list, which also shows the inactive type.
        $this->getJson('/api/v1/customer-categories')->assertOk()->assertJsonCount(6, 'data');
        $this->assertSame(self::FIVE, array_column($this->getJson('/api/v1/customer-categories?activeOnly=1')->json('data'), 'name'));
    }

    public function test_customer_types_requires_sign_in(): void
    {
        $this->getJson('/api/v1/customer-types')->assertUnauthorized();
    }

    public function test_loan_category_form_options_offer_only_the_active_types_in_order(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $this->extraType($admin->company_id, 'INACTIVE_TYPE', ['is_active' => false]);

        $options = $this->getJson('/api/v1/settings/options/customer-categories')->assertOk()->json('data');

        $this->assertSame(self::FIVE, array_column($options, 'label'));
    }

    public function test_registration_accepts_each_active_type_and_rejects_others_with_customer_type_wording(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);

        foreach (CustomerCategory::query()->selectable($admin->company_id)->get() as $type) {
            $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['customerCategoryId' => $type->id]))
                ->assertJsonMissingValidationErrors('customerCategoryId');
        }

        $inactive = $this->extraType($admin->company_id, 'INACTIVE_TYPE', ['is_active' => false]);
        $foreign = $this->extraType(Company::factory()->create()->id, 'FOREIGN_TYPE');

        foreach ([999999, $inactive->id, $foreign->id] as $id) {
            $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['customerCategoryId' => $id]))
                ->assertUnprocessable()
                ->assertJsonPath('errors.customerCategoryId.0', 'The selected customer type is not an active customer type of this company.');
        }

        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['customerCategoryId' => 'Mtumishi wa Umma']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.customerCategoryId.0', 'Select a customer type from the list.');
    }

    public function test_an_existing_customer_keeps_a_since_deactivated_type_on_update(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $student = $this->type($admin, 'MWANAFUNZI_CHUO');
        $customer = Customer::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'customer_category_id' => $student->id]);
        $student->update(['is_active' => false]);
        $retired = $this->type($admin, 'MSTAAFU_UMMA');
        $retired->update(['is_active' => false]);

        $this->putJson("/api/v1/customers/{$customer->id}", ['customerCategoryId' => $student->id])->assertJsonMissingValidationErrors('customerCategoryId');
        $this->putJson("/api/v1/customers/{$customer->id}", ['customerCategoryId' => $retired->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.customerCategoryId.0', 'The selected customer type is not an active customer type of this company.');
    }

    public function test_eligibility_reasons_say_customer_type(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $type = $this->type($admin, 'SEKTA_BINAFSI');
        $allowed = LoanCategory::factory()->forCustomerType($type)->create(['amount_from' => 100000, 'amount_to' => 500000]);
        $allowed->branches()->attach($admin->branch_id);
        $other = LoanCategory::factory()->forCustomerType($this->type($admin, 'WAJASIRIAMALI'))->create();
        $other->branches()->attach($admin->branch_id);
        $customer = Customer::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'customer_category_id' => $type->id]);
        $eligibility = app(CustomerEligibility::class);

        $this->assertContains("This loan category is not available for the customer's customer type (Sekta Binafsi).", $eligibility->violations($customer, $other->id, 50000));
        $this->assertContains('Loan amount must be between 100,000 - 500,000', $eligibility->violations($customer->fresh(), $allowed->id, 50000));
        $this->assertContains('Loan amount must be between 100,000 - 500,000', $eligibility->violations($customer->fresh(), $allowed->id, 900000));
        $this->assertSame([], $eligibility->violations($customer->fresh(), $allowed->id, 300000));

        $type->update(['is_active' => false]);
        $this->assertContains('Customer type is inactive', $eligibility->violations($customer->fresh()));
    }
}

<?php

namespace Tests\Feature\Api\Settings;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Models\LoanCategory;
use Database\Seeders\CustomerModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Customer types: readable by any signed-in user, created / edited / deleted by the Super Administrator only
 * (CUSTOMER_MODULE_IMPLEMENTATION.md §3.3, §8.9).
 */
class CustomerCategoryApiTest extends TestCase
{
    use RefreshDatabase;

    private function employee(Employee $admin, string $role): Employee
    {
        return Employee::factory()->create([
            'company_id' => $admin->company_id, 'branch_id' => $admin->branch_id,
            'role_id' => $admin->company->roles()->where('key', $role)->value('id'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Mkulima', 'code' => 'mkulima', 'description' => 'Farmers', 'formTitle' => 'Taarifa za Mkulima', 'isActive' => true,
            'sortOrder' => 6, 'riskTier' => 'high', 'sector' => 'business', 'requiresSector' => false, 'requiresEmployer' => false,
            'requiresContract' => false, 'requiresSalary' => false, 'requiresExtraApproval' => true,
            'requiredDocuments' => ['national_id', ' '], 'optionalDocuments' => [], 'omittedStandardFields' => ['business_name'],
            'dynamicFormSchema' => [
                ['key' => 'zao', 'label' => 'Zao Kuu', 'type' => 'select', 'required' => true, 'options' => ['Mahindi', 'Kahawa']],
                ['key' => 'ekari', 'label' => 'Ekari', 'type' => 'number', 'required' => false],
            ],
        ], $overrides);
    }

    public function test_any_signed_in_user_reads_customer_types_with_the_resource_shape(): void
    {
        $admin = $this->signInAdmin();
        (new CustomerModuleSeeder)->seedCompany($admin->company);
        $student = CustomerCategory::where('code', 'MWANAFUNZI_CHUO')->sole();
        $student->update(['is_active' => false]);
        Customer::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'customer_category_id' => CustomerCategory::where('code', 'SEKTA_BINAFSI')->value('id')]);
        CustomerCategory::create(['company_id' => Company::factory()->create()->id, 'key' => 'x', 'code' => 'FOREIGN', 'name' => 'Foreign', 'required_documents' => [], 'form_schema' => []]);

        $this->actingAs($this->employee($admin, 'teller'));

        $this->getJson('/api/v1/customer-categories')->assertOk()->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.code', 'WATUMISHI_WA_UMMA')
            ->assertJsonPath('data.1.customerCount', 1)
            ->assertJsonStructure(['data' => [[
                'id', 'name', 'code', 'description', 'formTitle', 'isActive', 'sortOrder', 'riskTier', 'sector', 'requiredDocuments',
                'optionalDocuments', 'requiresSector', 'requiresEmployer', 'requiresContract', 'requiresSalary', 'dynamicFormSchema',
                'omittedStandardFields', 'requiresExtraApproval', 'createdBy', 'deletedAt', 'customerCount',
            ]]]);

        $active = $this->getJson('/api/v1/customer-categories?activeOnly=1')->assertOk()->assertJsonCount(4, 'data')->json('data');
        $this->assertNotContains('MWANAFUNZI_CHUO', array_column($active, 'code'));

        $this->getJson("/api/v1/customer-categories/{$student->id}")->assertOk()
            ->assertJsonPath('data.formTitle', 'Taarifa za Mwanafunzi wa Chuo')
            ->assertJsonPath('data.dynamicFormSchema.7.storesIn', 'monthlyIncome');
        $this->getJson('/api/v1/customer-categories/'.CustomerCategory::where('code', 'FOREIGN')->value('id'))->assertNotFound();
    }

    public function test_super_admin_creates_updates_and_deletes_customer_types(): void
    {
        $admin = $this->signInAdmin();
        $product = LoanCategory::factory()->create(['company_id' => $admin->company_id]);

        $this->postJson('/api/v1/customer-categories', $this->payload(['loanCategoryIds' => [$product->id], 'minLoanAmount' => 1000, 'maxLoanAmount' => 5000]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['loanCategoryIds', 'minLoanAmount', 'maxLoanAmount']);

        $created = $this->postJson('/api/v1/customer-categories', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'MKULIMA')
            ->assertJsonPath('data.requiredDocuments', ['national_id'])
            ->assertJsonPath('data.dynamicFormSchema.0.options', ['Mahindi', 'Kahawa'])
            ->assertJsonPath('data.createdBy', $admin->id)
            ->assertJsonMissingPath('data.loanCategories')
            ->assertJsonMissingPath('data.maxLoanAmount')
            ->json('data');
        $this->assertFalse(Schema::hasTable('main_categories') && DB::table('main_categories')->where('customer_category_id', $created['id'])->exists(), 'No main loan category is created for a customer type.');

        $this->postJson('/api/v1/customer-categories', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/customer-categories', $this->payload([
            'sector' => 'farming',
            'dynamicFormSchema' => [['key' => 'Bad Key', 'label' => '', 'type' => 'select'], ['key' => 'b', 'label' => 'B', 'type' => 'text', 'dependsOn' => 'later']],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['sector', 'dynamicFormSchema.0.key', 'dynamicFormSchema.0.label', 'dynamicFormSchema.0.options', 'dynamicFormSchema.1.dependsOn']);

        $this->putJson("/api/v1/customer-categories/{$created['id']}", $this->payload(['name' => 'Mkulima Mdogo', 'isActive' => false]))
            ->assertOk()->assertJsonPath('data.name', 'Mkulima Mdogo')->assertJsonPath('data.isActive', false);

        $blocking = LoanCategory::factory()->create(['company_id' => $admin->company_id, 'customer_category_id' => $created['id']]);
        $this->deleteJson("/api/v1/customer-categories/{$created['id']}")->assertUnprocessable()
            ->assertJsonPath('message', 'This customer type has loan categories and cannot be deleted. Move or delete its loan categories first.');
        $this->assertNotSoftDeleted('customer_categories', ['id' => $created['id']]);
        $blocking->delete();

        $this->deleteJson("/api/v1/customer-categories/{$created['id']}")->assertOk();
        $this->assertSoftDeleted('customer_categories', ['id' => $created['id']]);
    }

    public function test_only_super_admin_can_write_customer_types_and_refusal_comes_before_validation(): void
    {
        $admin = $this->signInAdmin();
        $category = CustomerCategory::create(['company_id' => $admin->company_id, 'key' => 'x', 'code' => 'X', 'name' => 'X', 'required_documents' => [], 'form_schema' => []]);

        foreach (['admin', 'branch_manager', 'loan_officer', 'finance', 'hr', 'teller'] as $role) {
            $this->actingAs($this->employee($admin, $role));

            $this->postJson('/api/v1/customer-categories', [])->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN')->assertJsonMissingPath('errors');
            $this->putJson("/api/v1/customer-categories/{$category->id}", [])->assertForbidden();
            $this->deleteJson("/api/v1/customer-categories/{$category->id}")->assertForbidden();
            $this->getJson('/api/v1/customer-categories')->assertOk();
        }

        $this->assertNotSoftDeleted($category);
    }
}

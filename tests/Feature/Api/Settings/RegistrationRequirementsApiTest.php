<?php

namespace Tests\Feature\Api\Settings;

use App\Models\AccountTypeRequirement;
use App\Models\Company;
use App\Models\CustomerCategory;
use App\Services\Customers\RequirementProfiles;
use Database\Seeders\CustomerModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /registration/requirements and requirement-profile resolution (CUSTOMER_MODULE_SPEC.md §2.4).
 */
class RegistrationRequirementsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_requirements_return_the_company_profiles(): void
    {
        $admin = $this->signInAdmin();
        (new CustomerModuleSeeder)->seedCompany($admin->company);
        AccountTypeRequirement::create([
            'company_id' => $admin->company_id, 'account_type_id' => 7, 'account_type_name' => 'Loan account',
            'requires_employment_details' => true, 'requires_bank_account' => true, 'min_guarantors' => 1, 'min_next_of_kin' => 1,
            'requires_customer_category' => true, 'requires_marital_status' => true, 'guidance' => 'Loan accounts need more.',
        ]);
        AccountTypeRequirement::create(['company_id' => Company::factory()->create()->id, 'account_type_id' => 9, 'account_type_name' => 'Foreign']);

        $this->getJson('/api/v1/registration/requirements')
            ->assertOk()
            ->assertJsonCount(2, 'data.profiles')
            ->assertJsonPath('data.profiles.0', [
                'accountTypeId' => null, 'accountTypeName' => null, 'isDefault' => true,
                'requiresEmploymentDetails' => false, 'requiresBusinessDetails' => false, 'requiresBankAccount' => false, 'requiresCardDetails' => false,
                'minGuarantors' => 0, 'minNextOfKin' => 0, 'requiresCustomerCategory' => false, 'requiresMaritalStatus' => false,
                'requiresAddress' => true, 'requiresIdentityDocument' => true, 'requiresCategoryDocuments' => false, 'categoryDocumentsEnforcedFrom' => null,
                'requiresFaceVerification' => true, 'requiresNidaVerification' => false, 'requiresOtpVerification' => false,
                'guidance' => 'Baseline requirements. Choosing an account type may add to these.',
            ])
            ->assertJsonPath('data.profiles.1.accountTypeId', 7)
            ->assertJsonPath('data.profiles.1.requiresAddress', true)
            ->assertJsonPath('data.profiles.1.minGuarantors', 1)
            ->assertJsonPath('data.profiles.1.guidance', 'Loan accounts need more.')
            ->assertJsonPath('data.externalVerification.nida.configured', false);
    }

    public function test_resolve_merges_baseline_account_type_and_customer_type_rows(): void
    {
        $company = Company::factory()->create();
        (new CustomerModuleSeeder)->seedCompany($company);
        $type = CustomerCategory::where('company_id', $company->id)->where('code', 'WAJASIRIAMALI')->sole();
        AccountTypeRequirement::create(['company_id' => $company->id, 'account_type_id' => 3, 'min_guarantors' => 2, 'requires_bank_account' => true]);
        AccountTypeRequirement::create(['company_id' => $company->id, 'customer_category_id' => $type->id, 'min_guarantors' => 1, 'min_next_of_kin' => 2, 'requires_business_details' => true, 'requires_address' => false]);
        $profiles = app(RequirementProfiles::class);

        $baseline = $profiles->resolve($company->id);
        $this->assertFalse($baseline['requires_bank_account']);
        $this->assertTrue($baseline['requires_address']);

        $merged = $profiles->resolve($company->id, 3, $type->id);
        $this->assertTrue($merged['requires_bank_account']);
        $this->assertTrue($merged['requires_business_details']);
        $this->assertTrue($merged['requires_address'], 'flags OR together, a row cannot drop a baseline requirement');
        $this->assertSame(2, $merged['min_guarantors']);
        $this->assertSame(2, $merged['min_next_of_kin']);

        $this->assertSame(RequirementProfiles::BASELINE, $profiles->resolve(Company::factory()->create()->id));
    }
}

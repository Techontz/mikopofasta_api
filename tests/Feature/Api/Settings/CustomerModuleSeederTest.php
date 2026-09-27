<?php

namespace Tests\Feature\Api\Settings;

use App\Models\AccountTypeRequirement;
use App\Models\Company;
use App\Models\CustomerCategory;
use App\Models\MasterData\Bank;
use App\Models\MasterData\BusinessType;
use App\Models\MasterData\DocumentType;
use App\Models\MasterData\GovernmentBody;
use App\Models\MasterData\GovernmentCadre;
use App\Models\MasterData\GovernmentDepartment;
use App\Models\MasterData\IdType;
use App\Models\MasterData\MaritalStatus;
use App\Models\MasterData\MobileMoneyProvider;
use App\Models\MasterData\PensionFund;
use App\Models\MasterData\PrivateCadre;
use App\Models\MasterData\PrivateEmployer;
use App\Services\Customers\RequirementProfiles;
use App\Services\Customers\StepTwoFields;
use Database\Seeders\CustomerModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CUSTOMER_MODULE_IMPLEMENTATION.md §8.1 — seeded customer types and Step 2 composition.
 */
class CustomerModuleSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function reference(): array
    {
        return json_decode((string) file_get_contents(database_path('data/customer-module-types.json')), true);
    }

    public function test_seeder_creates_exactly_the_five_types_and_the_baseline_profile(): void
    {
        $company = Company::factory()->create();
        (new CustomerModuleSeeder)->seedCompany($company);

        $types = CustomerCategory::where('company_id', $company->id)->orderBy('sort_order')->get();

        $this->assertSame(
            [
                ['Mtumishi wa Umma', 'WATUMISHI_WA_UMMA', 'employment', 'Taarifa za Mtumishi wa Umma', 1, 'medium'],
                ['Sekta Binafsi', 'SEKTA_BINAFSI', 'employment', 'Taarifa za Sekta Binafsi', 2, 'medium'],
                ['Mjasiriamali/Mfanyabiashara', 'WAJASIRIAMALI', 'business', 'Taarifa za Mjasiriamali/Mfanyabiashara', 3, 'medium'],
                ['Mwanafunzi wa Chuo', 'MWANAFUNZI_CHUO', 'other', 'Taarifa za Mwanafunzi wa Chuo', 4, 'high'],
                ['Mstaafu (Umma)', 'MSTAAFU_UMMA', 'employment', 'Taarifa za Mstaafu (Umma)', 5, 'low'],
            ],
            $types->map(fn (CustomerCategory $type): array => [$type->name, $type->code, $type->sector, $type->form_title, $type->sort_order, $type->risk_tier])->all(),
        );

        foreach ($types as $type) {
            $this->assertTrue($type->is_active);
            $this->assertFalse($type->requires_sector || $type->requires_employer || $type->requires_contract || $type->requires_salary || $type->requires_extra_approval);
        }
        $this->assertSame(['confirmation_letter', 'salary_slip', 'bank_card', 'employee_id', 'national_id'], $types[0]->required_documents);
        $this->assertSame(['place_of_employment', 'check_number', 'monthly_income'], $types[1]->omitted_standard_fields);

        $baseline = AccountTypeRequirement::where('company_id', $company->id)->sole();
        $this->assertNull($baseline->account_type_id);
        $this->assertNull($baseline->customer_category_id);
        $this->assertTrue($baseline->requires_address && $baseline->requires_identity_document && $baseline->requires_face_verification);
        $this->assertFalse($baseline->requires_category_documents || $baseline->requires_bank_account || $baseline->requires_customer_category);
        $this->assertSame(0, $baseline->min_guarantors);
        $this->assertSame('Baseline requirements. Choosing an account type may add to these.', $baseline->guidance);
    }

    public function test_seeder_is_idempotent_maps_legacy_keys_and_restores_soft_deleted_types(): void
    {
        $company = Company::factory()->create();
        $legacy = CustomerCategory::create([
            'company_id' => $company->id, 'key' => 'mwanafunzi', 'name' => 'Old student', 'risk_level' => 'high',
            'required_documents' => ['Student ID'], 'form_schema' => [],
        ]);
        $seeder = new CustomerModuleSeeder;
        $seeder->seedCompany($company);

        $this->assertSame('MWANAFUNZI_CHUO', $legacy->fresh()->code);
        $this->assertSame('Mwanafunzi wa Chuo', $legacy->fresh()->name);

        $public = CustomerCategory::where('company_id', $company->id)->where('code', 'WATUMISHI_WA_UMMA')->sole();
        $public->update(['risk_tier' => 'high', 'required_documents' => ['national_id'], 'name' => 'Renamed']);
        $public->delete();

        $seeder->seedCompany($company);
        $seeder->seedCompany($company);

        $this->assertSame(5, CustomerCategory::withTrashed()->where('company_id', $company->id)->count());
        $restored = CustomerCategory::where('company_id', $company->id)->where('code', 'WATUMISHI_WA_UMMA')->sole();
        $this->assertSame($public->id, $restored->id);
        $this->assertSame('Mtumishi wa Umma', $restored->name);
        $this->assertSame('high', $restored->risk_tier, 'risk tier is only set on first create');
        $this->assertSame(['national_id'], $restored->required_documents, 'documents are only set on first create');
        $this->assertSame(1, AccountTypeRequirement::where('company_id', $company->id)->count());
    }

    public function test_reference_data_and_institution_registers_are_seeded_idempotently(): void
    {
        $seeder = new CustomerModuleSeeder;
        $seeder->seedReferenceData();

        $kyc = DocumentType::where('code', 'kyc_attachment')->sole();
        $this->assertSame('KYC Attachment', $kyc->name);
        $this->assertSame("The customer's KYC documents, scanned as a single file.", $kyc->description);
        $this->assertSame(['M-Pesa', 'Mixx by Yas', 'Airtel Money', 'HaloPesa'], MobileMoneyProvider::ordered()->pluck('name')->all());
        $this->assertSame(['National ID (NIDA)', 'Voter ID', "Driver's Licence", 'Passport', 'Work ID', 'TIN'], IdType::ordered()->pluck('name')->all());
        $this->assertSame(['Single', 'Married', 'Divorced', 'Widowed'], MaritalStatus::ordered()->pluck('name')->all());
        $this->assertSame(['PSSSF', 'NSSF', 'ZSSF (Zanzibar)', 'WCF'], PensionFund::ordered()->pluck('name')->all());
        $this->assertSame('ZSSF_ZANZIBAR', PensionFund::where('name', 'ZSSF (Zanzibar)')->value('code'));

        $body = GovernmentBody::where('name', 'OFISI YA RAIS - TAMISEMI')->sole();
        $department = GovernmentDepartment::where('government_body_id', $body->id)->where('name', 'Elimu Msingi')->sole();
        $this->assertSame('ELIMU_MSINGI', $department->code);
        $this->assertTrue(GovernmentCadre::where('government_department_id', $department->id)->where('name', 'Mwalimu')->exists());
        $this->assertTrue(PrivateEmployer::where('name', 'CRDB Bank')->exists());
        $this->assertTrue(PrivateCadre::where('name', 'Teller')->exists());
        $this->assertTrue(BusinessType::where('name', 'Mama Ntilie')->exists());
        $this->assertTrue(Bank::where('name', 'NMB Bank')->exists());

        $counts = fn (): array => [GovernmentCadre::withTrashed()->count(), PrivateCadre::withTrashed()->count(), BusinessType::withTrashed()->count(), Bank::withTrashed()->count()];
        $before = $counts();
        $department->delete();
        Bank::where('name', 'NMB Bank')->sole()->delete();

        $seeder->seedReferenceData();

        $this->assertSame($before, $counts());
        $this->assertNotNull(GovernmentDepartment::find($department->id), 'soft-deleted rows are restored');
        $this->assertTrue(Bank::where('name', 'NMB Bank')->exists());
    }

    public function test_step_two_composition_equals_the_reference_resolved_fields_for_each_type(): void
    {
        $company = Company::factory()->create();
        (new CustomerModuleSeeder)->seedCompany($company);
        $profile = app(RequirementProfiles::class)->resolve($company->id);
        $composer = app(StepTwoFields::class);

        foreach ($this->reference()['customerTypes'] as $definition) {
            $type = CustomerCategory::where('company_id', $company->id)->where('code', $definition['code'])->sole();
            $composed = $composer->compose($type, $profile);

            $this->assertEquals($definition['resolvedStep2Fields'], $composed, "Step 2 of {$definition['name']}");
            $this->assertSame(array_column($definition['resolvedStep2Fields'], 'key'), array_column($composed, 'key'));
            $this->assertSame(array_column($definition['resolvedStep2Fields'], 'label'), array_column($composed, 'label'));
            $this->assertSame(array_column($definition['resolvedStep2Fields'], 'required'), array_column($composed, 'required'));
        }
    }

    public function test_composition_applies_profile_blocks_conditional_fields_and_hides_fields_collected_elsewhere(): void
    {
        $composer = app(StepTwoFields::class);
        $type = new CustomerCategory([
            'sector' => 'employment', 'requires_sector' => true, 'requires_contract' => true, 'requires_employer' => true,
            'omitted_standard_fields' => ['basic_salary'],
            'dynamic_form_schema' => [
                ['key' => 'simu', 'label' => 'Simu', 'type' => 'text', 'required' => false, 'storesIn' => 'alternativePhone'],
                ['key' => 'kipato', 'label' => 'Kipato', 'type' => 'currency', 'required' => true, 'storesIn' => 'takeHome'],
            ],
        ]);

        $keys = array_column($composer->compose($type, ['requires_business_details' => true]), 'key');

        $this->assertSame([
            'kipato', 'sector_id', 'sector_category_id', 'employer_id', 'place_of_employment', 'check_number', 'contract_type_id',
            'contract_expiry_date', 'monthly_income', 'retirement_date', 'business_name', 'business_type', 'business_address', 'tin_number',
        ], $keys);
    }
}

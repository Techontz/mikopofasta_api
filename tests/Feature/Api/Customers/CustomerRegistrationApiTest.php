<?php

namespace Tests\Feature\Api\Customers;

use App\Models\AccountTypeRequirement;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerBankDetail;
use App\Models\MasterData\College;
use App\Models\MasterData\GovernmentBody;
use App\Models\MasterData\GovernmentCadre;
use App\Models\MasterData\GovernmentDepartment;
use App\Models\MasterData\IdType;
use App\Models\MasterData\PensionFund;
use Database\Seeders\CustomerModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Customers\Concerns\BuildsCustomerModule;
use Tests\TestCase;

/**
 * POST /customers — persistence of every type (§8.8), Basic Information (§8.3), MNO / Bank (§8.4) and
 * customer-type answers (§8.1 server side).
 */
class CustomerRegistrationApiTest extends TestCase
{
    use BuildsCustomerModule, RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function customerTypes(): array
    {
        return [
            'Mtumishi wa Umma' => ['WATUMISHI_WA_UMMA'],
            'Sekta Binafsi' => ['SEKTA_BINAFSI'],
            'Mjasiriamali/Mfanyabiashara' => ['WAJASIRIAMALI'],
            'Mwanafunzi wa Chuo' => ['MWANAFUNZI_CHUO'],
            'Mstaafu (Umma)' => ['MSTAAFU_UMMA'],
        ];
    }

    #[DataProvider('customerTypes')]
    public function test_each_customer_type_registers_and_every_value_round_trips(string $code): void
    {
        Storage::fake('local');
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $type = $this->type($admin, $code);
        [$typePayload, $expectedAnswers, $expectedColumns] = $this->typeAnswers($code);

        $payload = $this->registrationPayload($admin, $typePayload + [
            'customerCategoryId' => $type->id,
            'alternativePhone' => '0713000111',
            'email' => 'asha@example.com',
            'nationality' => 'Tanzanian',
            'nextOfKin' => [
                ['name' => 'Juma Hamisi', 'relationship' => 'spouse', 'phone' => '0754111222', 'address' => 'Kakonko'],
                ['name' => 'Mariam Hamisi', 'relationship' => 'sibling', 'phone' => '0754333444', 'address' => null],
            ],
            'guarantors' => [['name' => 'Peter Mushi', 'phone' => '0715000999', 'nidaNumber' => '19800101123450000111', 'relationship' => 'friend', 'address' => 'Kigoma', 'occupation' => 'Mwalimu']],
        ]);

        $created = $this->postJson('/api/v1/customers', $payload)->assertCreated();
        $id = $created->json('data.id');

        $this->post("/api/v1/customers/{$id}/documents", ['documentType' => 'kyc_attachment', 'file' => UploadedFile::fake()->create('kyc.pdf', 300, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->post("/api/v1/customers/{$id}/face-verify", ['capture' => UploadedFile::fake()->image('capture.jpg', 640, 480)] + $this->faceReport(), ['Accept' => 'application/json'])->assertOk();

        $data = $this->getJson("/api/v1/customers/{$id}")->assertOk()->json('data');

        foreach (['firstName', 'middleName', 'lastName', 'dob', 'gender', 'phone', 'idTypeId', 'idNumber', 'maritalStatusId', 'dependentsCount', 'residenceType', 'regionId', 'districtId', 'wardId', 'wardName', 'streetName', 'alternativePhone', 'email', 'nationality', 'customerCategoryId', 'branchId', 'employeeId'] as $field) {
            $this->assertSame($payload[$field], $data[$field], "Step 1 field {$field}");
        }
        foreach ($expectedColumns as $field => $value) {
            $this->assertSame($value, $data[$field], "Step 2 column {$field}");
        }
        $this->assertEquals($expectedAnswers, $data['dynamicFormData']);

        $this->assertSame($type->name, $data['categoryName']);
        $this->assertSame('Married', $data['maritalStatus']);
        $this->assertSame(['Juma Hamisi', 'Mariam Hamisi'], array_column($data['nextOfKin'], 'name'));
        $this->assertSame('Kakonko', $data['nextOfKin'][0]['address']);
        $this->assertSame('sibling', $data['nextOfKin'][1]['relationship']);
        $this->assertSame(['name' => 'Peter Mushi', 'phone' => '0715000999', 'nidaNumber' => '19800101123450000111', 'relationship' => 'friend', 'address' => 'Kigoma', 'occupation' => 'Mwalimu'], array_intersect_key($data['guarantors'][0], array_flip(['name', 'phone', 'nidaNumber', 'relationship', 'address', 'occupation'])));
        $this->assertSame('kyc_attachment', $data['documents'][0]['documentType']);
        $this->assertSame('kyc.pdf', $data['documents'][0]['originalName']);
        $this->assertSame('passed', $data['faceScanStatus']);
        $this->assertSame(91, $data['faceScanQuality']);
        $this->assertNotNull($data['faceVerifiedAt']);
        $this->assertNotNull($data['photoPath']);
        $this->assertSame('completed', $data['kycStatus']);
        $this->assertSame('active', $data['status']);
        $this->assertSame('pending', $data['approvalStatus']);
        $this->assertMatchesRegularExpression('/^CU-\d{6}$/', $data['customerNumber']);
    }

    public function test_registration_with_the_seeded_registers_stores_the_answers(): void
    {
        $admin = $this->signInAdmin();
        $seeder = new CustomerModuleSeeder;
        $seeder->seedReferenceData();
        $this->seedCustomerModule($admin);

        $body = GovernmentBody::query()->has('governmentDepartments')->firstOrFail();
        $department = GovernmentDepartment::query()->where('government_body_id', $body->id)->has('governmentCadres')->firstOrFail();
        $cadre = GovernmentCadre::query()->where('government_department_id', $department->id)->firstOrFail();

        $response = $this->postJson('/api/v1/customers', $this->registrationPayload($admin, [
            'customerCategoryId' => $this->type($admin, 'MSTAAFU_UMMA')->id,
            'idTypeId' => IdType::where('name', 'National ID (NIDA)')->value('id'),
            'dynamicFormData' => [
                'mstaafu_taasisi' => (string) $body->id, 'mstaafu_idara' => (string) $department->id, 'mstaafu_cheo' => (string) $cadre->id,
                'makazi' => 'Kigoma', 'pensheni' => '400000', 'mfuko' => (string) PensionFund::where('name', 'PSSSF')->value('id'), 'namba_mfuko' => 'PS-1',
            ],
        ]))->assertCreated();

        $this->assertSame($cadre->id, $response->json('data.dynamicFormData.mstaafu_cheo'));
        $this->assertSame(400000, $response->json('data.dynamicFormData.pensheni'));
    }

    public function test_new_customer_has_generated_number_statuses_and_an_audit_entry(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        Customer::factory()->create(['branch_id' => $admin->branch_id, 'customer_number' => 'CU-000041']);

        $response = $this->postJson('/api/v1/customers', $this->registrationPayload($admin))
            ->assertCreated()
            ->assertJsonPath('data.customerNumber', 'CU-000042')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.approvalStatus', 'pending')
            ->assertJsonPath('data.kycStatus', 'incomplete')
            ->assertJsonPath('data.createdBy', $admin->id);

        $this->assertStringContainsString('"dynamicFormData":{}', $response->getContent());

        $audit = AuditLog::where('action', 'Customer.registered')->where('auditable_id', $response->json('data.id'))->firstOrFail();
        $this->assertEquals(['customer_number' => 'CU-000042', 'kyc_status' => 'incomplete', 'approval_status' => 'pending'], $audit->after);
        $this->assertSame('pending', Customer::findOrFail($response->json('data.id'))->status);
    }

    public function test_basic_information_static_rules(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);

        $this->postJson('/api/v1/customers', ['branchId' => $admin->branch_id])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_FAILED')
            ->assertJsonPath('errors.dob.0', 'Date of birth is required.')
            ->assertJsonValidationErrors(['firstName', 'lastName', 'gender', 'phone', 'dynamicFormData', 'nextOfKin', 'guarantors']);

        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['dob' => now()->toDateString()]))
            ->assertUnprocessable()->assertJsonPath('errors.dob.0', 'Date of birth must be in the past.');
        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['dob' => now()->addDay()->toDateString()]))
            ->assertUnprocessable()->assertJsonValidationErrors('dob');

        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, [
            'firstName' => str_repeat('a', 81), 'gender' => 'other', 'phone' => '12345678', 'middleName' => str_repeat('m', 81),
            'dependentsCount' => 51, 'residenceType' => 'hostel', 'email' => 'not-an-email', 'basicSalary' => -1, 'paymentMethod' => 'card',
            'alternativePhone' => '123', 'idNumber' => str_repeat('1', 61), 'wardName' => str_repeat('w', 121),
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'firstName', 'gender', 'phone', 'middleName', 'dependentsCount', 'residenceType', 'email', 'basicSalary', 'paymentMethod', 'alternativePhone', 'idNumber', 'wardName',
        ]);

        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['branchId' => 999999, 'idTypeId' => 999999, 'maritalStatusId' => 999999, 'regionId' => 999999, 'customerCategoryId' => 999999, 'bankId' => 999999, 'mobileMoneyProviderId' => 999999]))
            ->assertUnprocessable()->assertJsonValidationErrors(['branchId', 'idTypeId', 'maritalStatusId', 'regionId', 'customerCategoryId', 'bankId', 'mobileMoneyProviderId']);

        IdType::query()->whereKey($this->ids['nida'])->update(['is_active' => false]);
        $this->postJson('/api/v1/customers', $this->registrationPayload($admin))->assertUnprocessable()->assertJsonValidationErrors('idTypeId');
    }

    public function test_duplicate_phone_and_identity_numbers_are_refused(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['phone' => '0754000001', 'nidaNumber' => '19900415123450000113', 'tinNumber' => '123-456-789']))->assertCreated();

        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['phone' => '0754000001', 'nidaNumber' => '19900415123450000113', 'tinNumber' => '123-456-789']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.nidaNumber.0', 'A customer with this NIDA number is already registered.')
            ->assertJsonValidationErrors(['phone', 'tinNumber']);

        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['nidaNumber' => '123']))->assertUnprocessable()->assertJsonValidationErrors('nidaNumber');
    }

    public function test_identity_and_address_are_enforced_by_the_baseline_profile(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);

        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['idTypeId' => $this->ids['nida'], 'idNumber' => null, 'regionId' => null, 'districtId' => null, 'wardId' => null]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.idTypeId.0', 'An identity document is required — choose the ID type and enter the number shown on it.')
            ->assertJsonPath('errors.regionId.0', 'Region is required.')
            ->assertJsonPath('errors.districtId.0', 'District must be selected.');

        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['idTypeId' => null, 'idNumber' => null, 'passportNumber' => 'AB123456']))->assertCreated();

        $typed = $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['wardId' => null, 'wardName' => 'Kata Mpya']))->assertCreated();
        $this->assertNull($typed->json('data.wardId'));
        $this->assertSame('Kata Mpya', $typed->json('data.wardName'));
    }

    public function test_profile_rules_for_an_account_type(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        AccountTypeRequirement::create([
            'company_id' => $admin->company_id, 'account_type_id' => 7, 'account_type_name' => 'Loan account',
            'requires_employment_details' => true, 'requires_business_details' => true, 'requires_bank_account' => true, 'requires_card_details' => true,
            'requires_customer_category' => true, 'requires_marital_status' => true, 'min_guarantors' => 1, 'min_next_of_kin' => 2,
        ]);

        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['accountTypeId' => 7, 'maritalStatusId' => null]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.maritalStatusId.0', 'Marital status is required for this account type.')
            ->assertJsonPath('errors.customerCategoryId.0', 'A customer type is required for this account type — it decides which loan products the customer may take.')
            ->assertJsonPath('errors.employer.0', 'An employer or place of employment is required for this account type.')
            ->assertJsonPath('errors.workType.0', 'Work type or type of employment is required for this account type.')
            ->assertJsonPath('errors.takeHome.0', 'An income figure is required for this account type.')
            ->assertJsonPath('errors.businessName.0', 'Business name is required for this account type.')
            ->assertJsonPath('errors.businessType.0', 'Business type is required for this account type.')
            ->assertJson(['errors' => ['bankDetails.accountNumber' => ['A bank account or a mobile money wallet number is required for this account type.']]])
            ->assertJsonPath('errors.cardNumber.0', 'Card details are required for this account type.')
            ->assertJsonPath('errors.guarantors.0', 'At least 1 guarantor is required for this account type.')
            ->assertJsonPath('errors.nextOfKin.0', 'At least 2 next of kin are required for this account type.');

        AccountTypeRequirement::where('account_type_id', 7)->update(['min_guarantors' => 2, 'min_next_of_kin' => 1]);
        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['accountTypeId' => 7, 'nextOfKin' => []]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.guarantors.0', 'At least 2 guarantors are required for this account type.')
            ->assertJsonPath('errors.nextOfKin.0', 'At least 1 next of kin is required for this account type.');

        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, [
            'accountTypeId' => 7, 'customerCategoryId' => $this->type($admin, 'WAJASIRIAMALI')->id,
            'dynamicFormData' => ['sekta' => $this->ids['businessSector'], 'aina' => $this->ids['businessType'], 'jina_biashara' => 'Duka', 'mapato' => 500000, 'mahali_biashara' => 'Soko'],
            'placeOfEmployment' => 'Soko Kuu', 'employmentType' => 'Self employed', 'monthlyIncome' => 500000, 'businessName' => 'Duka', 'businessType' => 'Rejareja',
            'paymentMethod' => 'mno', 'mobileMoneyProviderId' => $this->ids['mpesa'], 'walletNumber' => '0754000000', 'cardLastFour' => '4242',
            'guarantors' => [['name' => 'A', 'phone' => '0754000011', 'relationship' => 'friend'], ['name' => 'B', 'phone' => '0754000012', 'relationship' => 'colleague']],
        ]))->assertCreated();
    }

    public function test_next_of_kin_rows_are_validated(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);

        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, [
            'nextOfKin' => [['name' => '', 'relationship' => 'cousin', 'phone' => '12', 'address' => str_repeat('a', 256)]],
            'guarantors' => [['name' => '', 'phone' => '', 'relationship' => 'boss', 'nidaNumber' => str_repeat('1', 31), 'occupation' => str_repeat('o', 151)]],
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'nextOfKin.0.name', 'nextOfKin.0.relationship', 'nextOfKin.0.phone', 'nextOfKin.0.address',
            'guarantors.0.name', 'guarantors.0.phone', 'guarantors.0.relationship', 'guarantors.0.nidaNumber', 'guarantors.0.occupation',
        ]);
    }

    public function test_assigned_officer_can_only_be_another_employee_with_assign_officer(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $officer = $this->employeeWithRole($admin, 'loan_officer');
        $colleague = $this->employeeWithRole($admin, 'loan_officer');

        $this->actingAs($officer)->postJson('/api/v1/customers', $this->registrationPayload($admin, ['employeeId' => $colleague->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('employeeId');
        $this->actingAs($officer)->postJson('/api/v1/customers', $this->registrationPayload($admin, ['employeeId' => null]))
            ->assertCreated()->assertJsonPath('data.employeeId', $officer->id);

        $manager = $this->employeeWithRole($admin, 'branch_manager');
        $this->actingAs($manager)->postJson('/api/v1/customers', $this->registrationPayload($admin, ['employeeId' => $colleague->id]))
            ->assertCreated()->assertJsonPath('data.employeeId', $colleague->id);
    }

    public function test_verification_claims_are_refused_when_the_integration_is_not_configured(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);

        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['nidaVerifiedAt' => now()->toDateTimeString(), 'otpVerifiedAt' => now()->toDateTimeString()]))
            ->assertUnprocessable()->assertJsonValidationErrors(['nidaVerifiedAt', 'otpVerifiedAt']);

        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['faceVerifiedAt' => now()->toDateTimeString()]))
            ->assertCreated()->assertJsonPath('data.faceVerifiedAt', null);
    }

    public function test_mno_and_bank_require_their_fields(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);

        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['paymentMethod' => 'mno']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.mobileMoneyProviderId.0', 'Choose the mobile money provider.')
            ->assertJsonPath('errors.walletNumber.0', 'Enter the number the wallet is registered on.');

        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['paymentMethod' => 'bank']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.bankId.0', 'Choose the bank.')
            ->assertJson(['errors' => ['bankDetails.accountNumber' => ['Enter the account number.']]]);

        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['paymentMethod' => 'bank', 'bankId' => $this->ids['crdb'], 'bankDetails' => ['bankName' => '', 'accountNumber' => '', 'accountName' => '']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['bankDetails.bankName', 'bankDetails.accountNumber', 'bankDetails.accountName']);
    }

    public function test_mno_persistence(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);

        $response = $this->postJson('/api/v1/customers', $this->registrationPayload($admin, [
            'paymentMethod' => 'mno', 'mobileMoneyProviderId' => $this->ids['mpesa'], 'mobileMoneyProvider' => 'M-Pesa', 'walletNumber' => '0754000000',
        ]))->assertCreated()
            ->assertJsonPath('data.paymentMethod', 'mno')
            ->assertJsonPath('data.mobileMoneyProviderId', $this->ids['mpesa'])
            ->assertJsonPath('data.mobileMoneyProvider', 'M-Pesa')
            ->assertJsonPath('data.walletNumber', '0754000000')
            ->assertJsonPath('data.bankId', null)
            ->assertJsonPath('data.accountNumber', null);

        $this->assertSame(0, CustomerBankDetail::where('customer_id', $response->json('data.id'))->count());
    }

    public function test_bank_persistence_creates_the_bank_details_row(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);

        $response = $this->postJson('/api/v1/customers', $this->registrationPayload($admin, [
            'phone' => '0754999888', 'paymentMethod' => 'bank', 'bankId' => $this->ids['crdb'], 'bankBranch' => 'Kigoma', 'accountName' => 'Asha Hamisi',
            'bankDetails' => ['bankName' => 'CRDB Bank', 'accountNumber' => '0150123456789', 'accountName' => 'Asha Hamisi', 'phoneNumber' => '0754999888'],
        ]))->assertCreated()
            ->assertJsonPath('data.paymentMethod', 'bank')
            ->assertJsonPath('data.bankId', $this->ids['crdb'])
            ->assertJsonPath('data.bankName', 'CRDB Bank')
            ->assertJsonPath('data.bankBranch', 'Kigoma')
            ->assertJsonPath('data.accountName', 'Asha Hamisi')
            ->assertJsonPath('data.accountNumber', '0150123456789')
            ->assertJsonPath('data.bankDetails.phoneNumber', '0754999888');

        $row = CustomerBankDetail::where('customer_id', $response->json('data.id'))->firstOrFail();
        $this->assertSame(['CRDB Bank', '0150123456789', 'Asha Hamisi', '0754999888'], [$row->bank_name, $row->account_number, $row->account_name, $row->phone]);
    }

    public function test_payment_method_is_derived_when_absent(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);

        $payload = $this->registrationPayload($admin, ['walletNumber' => '0754000000']);
        unset($payload['paymentMethod']);
        $this->postJson('/api/v1/customers', $payload)->assertCreated()->assertJsonPath('data.paymentMethod', 'mno');

        $payload = $this->registrationPayload($admin, ['bankDetails' => ['bankName' => 'CRDB Bank', 'accountNumber' => '0150', 'accountName' => 'Asha']]);
        unset($payload['paymentMethod']);
        $this->postJson('/api/v1/customers', $payload)->assertCreated()->assertJsonPath('data.paymentMethod', 'bank');

        $payload = $this->registrationPayload($admin);
        unset($payload['paymentMethod']);
        $this->postJson('/api/v1/customers', $payload)->assertCreated()->assertJsonPath('data.paymentMethod', null);
    }

    public function test_missing_required_answers_are_reported_together_and_optional_ones_do_not_block(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $type = $this->type($admin, 'MWANAFUNZI_CHUO');

        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['customerCategoryId' => $type->id, 'dynamicFormData' => ['level' => 'Shahada (Degree)']]))
            ->assertUnprocessable()
            ->assertJson(['errors' => ['dynamicFormData.chuo' => ['Chuo is required.']]])
            ->assertJson(['errors' => ['dynamicFormData.kozi' => ['Kozi is required.']]])
            ->assertJson(['errors' => ['dynamicFormData.mwaka' => ['Mwaka wa Ngapi is required.']]])
            ->assertJson(['errors' => ['dynamicFormData.mdhamini' => ['Jina la Mdhamini is required.']]])
            ->assertJson(['errors' => ['dynamicFormData.mdhamini_simu' => ['Simu ya Mdhamini is required.']]])
            ->assertJsonMissingValidationErrors('dynamicFormData.email_chuo')
            ->assertJsonMissingValidationErrors('monthlyIncome');
    }

    public function test_answer_formats_options_and_cascades_are_enforced(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);

        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, [
            'customerCategoryId' => $this->type($admin, 'WATUMISHI_WA_UMMA')->id,
            'dynamicFormData' => ['taasisi' => $this->ids['body'], 'idara' => $this->ids['otherDepartment'], 'cheo' => 999999, 'kituo' => 'Kituo', 'check_number' => 'C1', 'aina_ajira' => 'Kibarua'],
        ]))->assertUnprocessable()
            ->assertJson(['errors' => ['dynamicFormData.idara' => ['The selected Idara does not belong to the chosen Wizara / Taasisi ya Serikali.']]])
            ->assertJson(['errors' => ['dynamicFormData.cheo' => ['The selected Cheo does not exist.']]])
            ->assertJson(['errors' => ['dynamicFormData.aina_ajira' => ['Aina ya Ajira must be one of the listed options.']]]);

        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, [
            'customerCategoryId' => $this->type($admin, 'WAJASIRIAMALI')->id,
            'dynamicFormData' => ['sekta' => $this->ids['businessSector'], 'aina' => $this->ids['businessType'], 'jina_biashara' => 'Duka', 'mapato' => 'mingi', 'mahali_biashara' => 'Soko'],
        ]))->assertUnprocessable()->assertJson(['errors' => ['dynamicFormData.mapato' => ['Mapato ya Wastani kwa Mwezi (TZS) must be a number.']]]);
    }

    public function test_unknown_keys_are_dropped_and_stores_in_answers_go_to_their_column(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);

        $response = $this->postJson('/api/v1/customers', $this->registrationPayload($admin, [
            'customerCategoryId' => $this->type($admin, 'MWANAFUNZI_CHUO')->id,
            'monthlyIncome' => 150000,
            'dynamicFormData' => [
                'chuo' => $this->ids['college'], 'kozi' => $this->ids['course'], 'level' => 'Shahada (Degree)', 'mwaka' => 'Mwaka wa 2',
                'mdhamini' => 'Baba', 'mdhamini_simu' => '0754000999', 'monthly_income' => 999, 'kadi_muda' => 'x', 'hacker' => 'y',
            ],
        ]))->assertCreated()->assertJsonPath('data.monthlyIncome', 150000);

        $customer = Customer::findOrFail($response->json('data.id'));
        $this->assertSame(150000, (int) $customer->monthly_income);
        $this->assertArrayNotHasKey('monthly_income', $customer->dynamic_form_data);
        $this->assertArrayNotHasKey('kadi_muda', $customer->dynamic_form_data);
        $this->assertArrayNotHasKey('hacker', $customer->dynamic_form_data);
    }

    public function test_required_when_applies_only_for_matching_values_or_codes(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $type = $this->type($admin, 'MWANAFUNZI_CHUO');
        $type->update(['dynamic_form_schema' => [
            ['key' => 'chuo', 'type' => 'select', 'label' => 'Chuo', 'required' => false, 'dataSource' => 'colleges'],
            ['key' => 'level', 'type' => 'select', 'label' => 'Level', 'required' => false, 'options' => ['Masters', 'Degree']],
            ['key' => 'thesis', 'type' => 'text', 'label' => 'Thesis', 'required' => false, 'requiredWhen' => ['field' => 'level', 'equals' => ['Masters']]],
            ['key' => 'campus', 'type' => 'text', 'label' => 'Campus', 'required' => false, 'requiredWhen' => ['field' => 'chuo', 'equals' => [College::find($this->ids['college'])->code]]],
        ]]);

        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['customerCategoryId' => $type->id, 'dynamicFormData' => ['level' => 'Degree']]))->assertCreated();

        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['customerCategoryId' => $type->id, 'dynamicFormData' => ['level' => 'Masters', 'chuo' => $this->ids['college']]]))
            ->assertUnprocessable()
            ->assertJson(['errors' => ['dynamicFormData.thesis' => ['Thesis is required.']]])
            ->assertJson(['errors' => ['dynamicFormData.campus' => ['Campus is required.']]]);
    }

    /**
     * Step 2 payload, the stored answers and the column values expected back for each type.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function typeAnswers(string $code): array
    {
        $ids = $this->ids;
        $mno = ['paymentMethod' => 'mno', 'mobileMoneyProviderId' => $ids['mpesa'], 'mobileMoneyProvider' => 'M-Pesa', 'walletNumber' => '0754000000'];
        $bank = ['paymentMethod' => 'bank', 'bankId' => $ids['crdb'], 'bankBranch' => 'Kigoma', 'accountName' => 'Asha Hamisi', 'bankDetails' => ['bankName' => 'CRDB Bank', 'accountNumber' => '0150123456789', 'accountName' => 'Asha Hamisi', 'phoneNumber' => '0754000000']];

        return match ($code) {
            'WATUMISHI_WA_UMMA' => [
                ['dynamicFormData' => ['taasisi' => (string) $ids['body'], 'idara' => (string) $ids['department'], 'cheo' => (string) $ids['cadre'], 'kituo' => 'Hospitali ya Wilaya', 'check_number' => 'CHK-123', 'aina_ajira' => 'Ajira ya Kudumu'],
                    'basicSalary' => 900000, 'takeHome' => 650000, 'retirementDate' => '2050-06-30'] + $mno,
                ['taasisi' => $ids['body'], 'idara' => $ids['department'], 'cheo' => $ids['cadre'], 'kituo' => 'Hospitali ya Wilaya', 'check_number' => 'CHK-123', 'aina_ajira' => 'Ajira ya Kudumu'],
                ['placeOfEmployment' => null, 'basicSalary' => 900000, 'takeHome' => 650000, 'monthlyIncome' => null, 'retirementDate' => '2050-06-30', 'checkNumber' => null] + $mno,
            ],
            'SEKTA_BINAFSI' => [
                ['dynamicFormData' => ['sb_sekta' => $ids['privateSector'], 'sb_taasisi' => $ids['privateEmployer'], 'sb_idara' => $ids['privateDepartment'], 'sb_cheo' => $ids['privateCadre'], 'sb_kituo' => 'Tawi la Kigoma', 'sb_aina_mkataba' => 'Mkataba wa Muda', 'sb_kitambulisho' => 'NMB-778'],
                    'basicSalary' => 1200000, 'takeHome' => 800000, 'retirementDate' => '2055-01-31'] + $bank,
                ['sb_sekta' => $ids['privateSector'], 'sb_taasisi' => $ids['privateEmployer'], 'sb_idara' => $ids['privateDepartment'], 'sb_cheo' => $ids['privateCadre'], 'sb_kituo' => 'Tawi la Kigoma', 'sb_aina_mkataba' => 'Mkataba wa Muda', 'sb_kitambulisho' => 'NMB-778'],
                ['basicSalary' => 1200000, 'takeHome' => 800000, 'retirementDate' => '2055-01-31', 'paymentMethod' => 'bank', 'bankId' => $ids['crdb'], 'bankName' => 'CRDB Bank', 'bankBranch' => 'Kigoma', 'accountName' => 'Asha Hamisi', 'accountNumber' => '0150123456789',
                    'bankDetails' => ['bankName' => 'CRDB Bank', 'accountNumber' => '0150123456789', 'accountName' => 'Asha Hamisi', 'phoneNumber' => '0754000000', 'checkNumber' => null]],
            ],
            'WAJASIRIAMALI' => [
                ['dynamicFormData' => ['sekta' => $ids['businessSector'], 'aina' => $ids['businessType'], 'jina_biashara' => 'Duka la Asha', 'muda_biashara' => '5', 'mapato' => '1200000', 'wafanyakazi' => 3, 'mahali_biashara' => 'Soko Kuu Kakonko'],
                    'tinNumber' => '123-456-789'],
                ['sekta' => $ids['businessSector'], 'aina' => $ids['businessType'], 'jina_biashara' => 'Duka la Asha', 'muda_biashara' => 5, 'mapato' => 1200000, 'wafanyakazi' => 3, 'mahali_biashara' => 'Soko Kuu Kakonko'],
                ['tinNumber' => '123-456-789', 'paymentMethod' => null, 'businessName' => null],
            ],
            'MWANAFUNZI_CHUO' => [
                ['dynamicFormData' => ['chuo' => $ids['college'], 'kozi' => $ids['course'], 'level' => 'Shahada (Degree)', 'mwaka' => 'Mwaka wa 3', 'email_chuo' => 'asha@udsm.ac.tz', 'mdhamini' => 'Hamisi Juma', 'mdhamini_simu' => '0713222333'],
                    'monthlyIncome' => 150000] + $mno,
                ['chuo' => $ids['college'], 'kozi' => $ids['course'], 'level' => 'Shahada (Degree)', 'mwaka' => 'Mwaka wa 3', 'email_chuo' => 'asha@udsm.ac.tz', 'mdhamini' => 'Hamisi Juma', 'mdhamini_simu' => '0713222333'],
                ['monthlyIncome' => 150000] + $mno,
            ],
            'MSTAAFU_UMMA' => [
                ['dynamicFormData' => ['mstaafu_taasisi' => $ids['body'], 'mstaafu_idara' => $ids['department'], 'mstaafu_cheo' => $ids['cadre'], 'makazi' => 'Kakonko Mjini', 'pensheni' => 400000, 'mfuko' => $ids['pensionFund'], 'namba_mfuko' => 'PSSSF-0099'],
                    'retirementDate' => '2020-07-01'] + $bank,
                ['mstaafu_taasisi' => $ids['body'], 'mstaafu_idara' => $ids['department'], 'mstaafu_cheo' => $ids['cadre'], 'makazi' => 'Kakonko Mjini', 'pensheni' => 400000, 'mfuko' => $ids['pensionFund'], 'namba_mfuko' => 'PSSSF-0099'],
                ['retirementDate' => '2020-07-01', 'paymentMethod' => 'bank', 'accountNumber' => '0150123456789', 'bankBranch' => 'Kigoma'],
            ],
        };
    }
}

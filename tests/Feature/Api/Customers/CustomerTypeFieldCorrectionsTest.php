<?php

namespace Tests\Feature\Api\Customers;

use App\Models\Customer;
use App\Models\Employee;
use App\Services\Customers\StepTwoFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\Customers\Concerns\BuildsCustomerModule;
use Tests\TestCase;

/**
 * Step 2 corrections approved by the product owner: Mtumishi wa Umma no longer asks Place of Employment / Monthly
 * Income (Basic Salary and Take Home stay), and Sekta Binafsi "Aina ya Mkataba" offers "Vibarua".
 */
class CustomerTypeFieldCorrectionsTest extends TestCase
{
    use BuildsCustomerModule, RefreshDatabase;

    public function test_mtumishi_wa_umma_step_two_has_no_place_of_employment_or_monthly_income(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);

        $fields = app(StepTwoFields::class)->compose($this->type($admin, 'WATUMISHI_WA_UMMA'));

        $this->assertSame(
            ['taasisi', 'idara', 'cheo', 'kituo', 'check_number', 'aina_ajira', 'basic_salary', 'take_home', 'retirement_date'],
            array_column($fields, 'key'),
        );
        $labels = array_column($fields, 'label');
        $this->assertNotContains('Place of Employment', $labels);
        $this->assertNotContains('Monthly Income', $labels);
        $this->assertContains('Basic Salary', $labels);
        $this->assertContains('Take Home', $labels);
    }

    public function test_mtumishi_wa_umma_registers_without_place_of_employment_or_monthly_income_and_keeps_salary_fields(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);

        $response = $this->postJson('/api/v1/customers', $this->mtumishiPayload($admin, ['basicSalary' => 900000, 'takeHome' => 650000]))
            ->assertCreated()
            ->assertJsonMissingValidationErrors(['placeOfEmployment', 'monthlyIncome'])
            ->assertJsonPath('data.basicSalary', 900000)
            ->assertJsonPath('data.takeHome', 650000)
            ->assertJsonPath('data.placeOfEmployment', null)
            ->assertJsonPath('data.monthlyIncome', null);

        $customer = Customer::findOrFail($response->json('data.id'));
        $this->assertEquals(900000, $customer->basic_salary);
        $this->assertEquals(650000, $customer->take_home);

        $this->postJson('/api/v1/customers', $this->mtumishiPayload($admin, ['basicSalary' => -1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('basicSalary');
    }

    public function test_place_of_employment_and_monthly_income_sent_for_mtumishi_wa_umma_are_not_stored(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);

        $id = $this->postJson('/api/v1/customers', $this->mtumishiPayload($admin, ['placeOfEmployment' => 'Hospitali ya Wilaya', 'monthlyIncome' => 700000, 'takeHome' => 650000]))
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('customers', ['id' => $id, 'place_of_employment' => null, 'monthly_income' => null, 'take_home' => 650000]);
    }

    public function test_other_customer_types_keep_their_standard_fields(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $reference = collect(json_decode((string) file_get_contents(database_path('data/customer-module-types.json')), true)['customerTypes'])->keyBy('code');

        foreach (['SEKTA_BINAFSI', 'WAJASIRIAMALI', 'MWANAFUNZI_CHUO', 'MSTAAFU_UMMA'] as $code) {
            $composed = array_map(fn (array $field): string => $field['key'], app(StepTwoFields::class)->compose($this->type($admin, $code)));
            $this->assertSame(array_column($reference[$code]['resolvedStep2Fields'], 'key'), $composed, $code);
        }

        // Mwanafunzi wa Chuo "Boom" still writes the monthly_income column.
        $this->postJson('/api/v1/customers', $this->registrationPayload($admin, [
            'customerCategoryId' => $this->type($admin, 'MWANAFUNZI_CHUO')->id,
            'dynamicFormData' => ['chuo' => $this->ids['college'], 'kozi' => $this->ids['course'], 'level' => 'Shahada (Degree)', 'mwaka' => 'Mwaka wa 3', 'mdhamini' => 'Hamisi Juma', 'mdhamini_simu' => '0713222333'],
            'monthlyIncome' => 150000,
        ]))->assertCreated()->assertJsonPath('data.monthlyIncome', 150000);
    }

    public function test_sekta_binafsi_offers_vibarua_and_it_persists_and_is_returned(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $type = $this->type($admin, 'SEKTA_BINAFSI');

        $field = collect(app(StepTwoFields::class)->compose($type))->firstWhere('key', 'sb_aina_mkataba');
        $this->assertSame('Aina ya Mkataba', $field['label']);
        $this->assertSame(['Ajira ya Kudumu', 'Mkataba wa Muda', 'Vibarua'], $field['options']);

        $response = $this->postJson('/api/v1/customers', $this->sektaBinafsiPayload($admin, 'Vibarua'))
            ->assertCreated()
            ->assertJsonPath('data.dynamicFormData.sb_aina_mkataba', 'Vibarua');
        $id = $response->json('data.id');

        $this->assertSame('Vibarua', Customer::findOrFail($id)->dynamic_form_data['sb_aina_mkataba']);
        $this->getJson("/api/v1/customers/{$id}")->assertOk()->assertJsonPath('data.dynamicFormData.sb_aina_mkataba', 'Vibarua');

        $this->postJson('/api/v1/customers', $this->sektaBinafsiPayload($admin, 'Kibarua cha Siku'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dynamicFormData.sb_aina_mkataba');
    }

    public function test_an_existing_sekta_binafsi_customer_can_be_edited_to_vibarua(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $payload = $this->sektaBinafsiPayload($admin, 'Ajira ya Kudumu');
        $id = $this->postJson('/api/v1/customers', $payload)->assertCreated()->json('data.id');

        $this->putJson("/api/v1/customers/{$id}", ['dynamicFormData' => ['sb_aina_mkataba' => 'Vibarua'] + $payload['dynamicFormData']])
            ->assertOk()
            ->assertJsonPath('data.dynamicFormData.sb_aina_mkataba', 'Vibarua');

        $this->assertSame('Vibarua', Customer::findOrFail($id)->dynamic_form_data['sb_aina_mkataba']);
    }

    public function test_migration_updates_existing_types_without_losing_administrator_changes(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $mtumishi = $this->type($admin, 'WATUMISHI_WA_UMMA');
        $sekta = $this->type($admin, 'SEKTA_BINAFSI');

        // The configuration as it was seeded before the correction, plus an administrator's own changes.
        $mtumishi->update(['omitted_standard_fields' => ['retirement_date']]);
        $sekta->update(['dynamic_form_schema' => collect($sekta->dynamic_form_schema)->map(fn (array $field): array => $field['key'] === 'sb_aina_mkataba'
            ? ['options' => ['Ajira ya Kudumu', 'Mkataba wa Muda', 'Mkataba wa Mradi']] + $field
            : $field)->all()]);

        $migration = require database_path('migrations/2026_09_14_062951_update_mtumishi_and_sekta_binafsi_step_two_fields.php');
        $migration->up();
        $migration->up();

        $this->assertSame(['retirement_date', 'place_of_employment', 'monthly_income'], $mtumishi->refresh()->omitted_standard_fields);
        $this->assertSame(
            ['Ajira ya Kudumu', 'Mkataba wa Muda', 'Mkataba wa Mradi', 'Vibarua'],
            collect($sekta->refresh()->dynamic_form_schema)->firstWhere('key', 'sb_aina_mkataba')['options'],
        );
        $this->assertSame(1, DB::table('customer_categories')->where('code', 'SEKTA_BINAFSI')->where('company_id', $admin->company_id)->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mtumishiPayload(Employee $admin, array $overrides = []): array
    {
        return $this->registrationPayload($admin, $overrides + [
            'customerCategoryId' => $this->type($admin, 'WATUMISHI_WA_UMMA')->id,
            'dynamicFormData' => ['taasisi' => (string) $this->ids['body'], 'idara' => (string) $this->ids['department'], 'cheo' => (string) $this->ids['cadre'], 'kituo' => 'Hospitali ya Wilaya', 'check_number' => 'CHK-123', 'aina_ajira' => 'Ajira ya Kudumu'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sektaBinafsiPayload(Employee $admin, string $contract): array
    {
        return $this->registrationPayload($admin, [
            'customerCategoryId' => $this->type($admin, 'SEKTA_BINAFSI')->id,
            'dynamicFormData' => ['sb_sekta' => $this->ids['privateSector'], 'sb_taasisi' => $this->ids['privateEmployer'], 'sb_idara' => $this->ids['privateDepartment'], 'sb_cheo' => $this->ids['privateCadre'], 'sb_kituo' => 'Tawi la Kigoma', 'sb_aina_mkataba' => $contract, 'sb_kitambulisho' => 'NMB-778'],
            'basicSalary' => 1200000, 'takeHome' => 800000,
        ]);
    }
}

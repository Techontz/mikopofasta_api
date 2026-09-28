<?php

namespace Tests\Feature\Api\Settings;

use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Models\InterestFormula;
use App\Models\LoanCategory;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeneralSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_formulas_enable_and_disable(): void
    {
        $this->signInAdmin();
        $formula = InterestFormula::create(['code' => 'FLAT', 'name' => 'FLAT RATE FORMULAR', 'is_enabled' => false]);

        $this->postJson("/api/v1/settings/formulas/{$formula->id}/enable")->assertOk()->assertJsonPath('message', 'Interest Formula Added successfully');
        $this->getJson('/api/v1/settings/options/formulas')->assertOk()->assertJsonPath('data.0.value', 'FLAT');
        $this->deleteJson("/api/v1/settings/formulas/{$formula->id}")->assertOk();
        $this->assertFalse($formula->fresh()->is_enabled);
    }

    public function test_main_loan_category_and_sub_category_endpoints_are_gone(): void
    {
        $admin = $this->signInAdmin();
        $type = CustomerCategory::factory()->create(['company_id' => $admin->company_id, 'name' => 'Mtumishi wa Umma']);

        $this->getJson('/api/v1/settings/main-categories')->assertNotFound();
        $this->getJson('/api/v1/settings/main-categories/1')->assertNotFound();
        $this->postJson('/api/v1/settings/main-categories/1/enable')->assertNotFound();
        $this->deleteJson('/api/v1/settings/main-categories/1')->assertNotFound();
        $this->getJson('/api/v1/settings/main-categories/1/sub-categories')->assertNotFound();
        $this->postJson('/api/v1/settings/sub-categories/1/enable')->assertNotFound();
        $this->deleteJson('/api/v1/settings/sub-categories/1')->assertNotFound();
        $this->getJson('/api/v1/settings/options/main-categories')->assertNotFound();

        $this->getJson('/api/v1/settings/options/customer-categories')->assertOk()->assertJsonPath('data.0.label', $type->name);
    }

    public function test_loan_fee_mode_and_product_fee(): void
    {
        $admin = $this->signInAdmin();
        $category = LoanCategory::factory()->create(['company_id' => $admin->company_id]);

        $this->putJson('/api/v1/settings/loan-fees/mode', ['fee_category' => 'GENERAL'])->assertOk();
        $this->assertSame('general', $admin->company->fresh()->loan_fee_mode);

        $this->putJson("/api/v1/settings/loan-fees/{$category->id}", [
            'loan_name' => 'WAJASILIAMALI', 'loan_price' => 20000, 'loan_perday' => 2000000, 'interest_formular' => 30,
            'fee_category_type' => 'PERCENTAGE', 'fee_value' => 5, 'insurance' => 3000,
        ])->assertOk()->assertJsonPath('message', 'Loan Fee Updated successfully');

        $this->getJson('/api/v1/settings/loan-fees')->assertOk()
            ->assertJsonPath('data.mode', 'GENERAL')
            ->assertJsonPath('data.categories.0.fee_type', 'percentage')
            ->assertJsonPath('data.categories.0.insurance', 0); // §47: insurance is no longer charged, whatever a client sends

        $this->putJson("/api/v1/settings/loan-fees/{$category->id}", ['fee_category_type' => 'X'])->assertUnprocessable();
    }

    public function test_penalty_reserve_and_company_profile(): void
    {
        $admin = $this->signInAdmin();
        $region = Region::create(['name' => 'Kagera']);

        $this->putJson('/api/v1/settings/penalty', ['action_penart' => 'MONEY VALUE', 'penart' => 5000])->assertOk()->assertJsonPath('message', 'Penalty Setting Updated successfully');
        $this->getJson('/api/v1/settings/penalty')->assertOk()->assertJsonPath('data.action_penart', 'MONEY VALUE')->assertJsonPath('data.penart', 5000);
        $this->putJson('/api/v1/settings/penalty', ['action_penart' => 'PERCENTAGE VALUE', 'penart' => 150])->assertUnprocessable();

        $this->putJson('/api/v1/settings/reserve', ['reserve' => 20])->assertOk()->assertJsonPath('message', 'Reserve Setting Updated successfully');
        $this->assertSame(20.0, (float) $admin->company->fresh()->reserve_percent);
        $this->putJson('/api/v1/settings/reserve', ['reserve' => 120])->assertUnprocessable()->assertJsonValidationErrors('reserve');
        $this->putJson('/api/v1/settings/loan-freeze', ['loan_freeze_days' => 30])->assertOk()->assertJsonPath('message', 'Loan Freeze Period Updated successfully');
        $this->getJson('/api/v1/settings/loan-freeze')->assertJsonPath('data.loan_freeze_days', 30);
        $this->putJson('/api/v1/settings/loan-freeze', ['loan_freeze_days' => -1])->assertUnprocessable()->assertJsonValidationErrors('loan_freeze_days');

        $this->putJson('/api/v1/settings/company', [
            'comp_name' => 'MIKOPO FASTA', 'comp_number' => '12345', 'adress' => 'Bukoba', 'comp_phone' => '0755000000',
            'comp_email' => 'info@example.com', 'region_id' => $region->id,
        ])->assertOk();
        $this->getJson('/api/v1/settings/company')->assertOk()->assertJsonPath('data.name', 'MIKOPO FASTA')->assertJsonPath('data.region', 'Kagera');

        $this->putJson('/api/v1/settings/company/password', ['oldpass' => 'wrong', 'newpass' => 'secret1', 'passconf' => 'secret1'])
            ->assertUnprocessable()->assertJsonValidationErrors('oldpass');
        $this->putJson('/api/v1/settings/company/password', ['oldpass' => 'password', 'newpass' => 'secret1', 'passconf' => 'secret1'])->assertOk();

        $officer = Employee::factory()->create([
            'company_id' => $admin->company_id, 'branch_id' => $admin->branch_id,
            'role_id' => $admin->company->roles()->where('key', 'loan_officer')->value('id'),
        ]);
        $this->actingAs($officer)->putJson('/api/v1/settings/reserve', ['reserve' => 1])->assertForbidden();
    }
}

<?php

namespace Tests\Feature\Api\Settings;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Models\InterestFormula;
use App\Models\LoanCategory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LoanCategoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        InterestFormula::create(['code' => 'SIMPLE', 'name' => 'SIMPLE FORMULAR', 'is_enabled' => true]);
        InterestFormula::create(['code' => 'FLAT', 'name' => 'FLAT RATE FORMULAR', 'is_enabled' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Employee $admin, array $overrides = []): array
    {
        return array_merge([
            'loan_name' => 'BIASHARA',
            'loan_price' => '20,000',
            'loan_perday' => '2,000,000',
            'interest_formular' => '30',
            'formular' => 'SIMPLE',
            'duration' => 'weekly',
            'from_repayment' => 1,
            'to_repayment' => 3,
            'fee_deduct' => 'YES',
            'penart' => 'NO',
            'aprove_status' => 'hq',
            'requires_mandate' => 'YES',
            'topup_percent' => '50',
            'take_home_percent' => '70',
            'customer_type_id' => $this->customerType($admin)->id,
        ], $overrides);
    }

    private function customerType(Employee $admin, string $code = 'WAJASIRIAMALI', string $name = 'Mjasiriamali/Mfanyabiashara'): CustomerCategory
    {
        return CustomerCategory::where('company_id', $admin->company_id)->where('code', $code)->first()
            ?? CustomerCategory::factory()->create(['company_id' => $admin->company_id, 'code' => $code, 'key' => strtolower($code), 'name' => $name]);
    }

    public function test_admin_creates_lists_updates_and_deletes_a_loan_category(): void
    {
        $admin = $this->signInAdmin();
        $type = $this->customerType($admin);

        $this->postJson('/api/v1/settings/loan-categories', $this->payload($admin))
            ->assertCreated()
            ->assertJsonPath('message', 'Loan Category Registered successfully')
            ->assertJsonPath('data.requires_mandate', true)
            ->assertJsonPath('data.customer_type_id', $type->id)
            ->assertJsonPath('data.customer_type', ['id' => $type->id, 'code' => 'WAJASIRIAMALI', 'name' => 'Mjasiriamali/Mfanyabiashara'])
            ->assertJsonMissingPath('data.main_category_id')
            ->assertJsonMissingPath('data.main_category')
            ->assertJsonMissingPath('data.customer_categories');

        $loanCategory = LoanCategory::firstWhere('name', 'BIASHARA');
        $this->assertSame(2000000.0, (float) $loanCategory->amount_to);
        $this->assertSame($type->id, $loanCategory->customer_category_id);

        $this->getJson('/api/v1/settings/loan-categories')
            ->assertOk()
            ->assertJsonPath('data.0.level_label', '20,000 - 2,000,000')
            ->assertJsonPath('data.0.customer_type.name', 'Mjasiriamali/Mfanyabiashara')
            ->assertJsonPath('data.0.customer_type.code', 'WAJASIRIAMALI')
            ->assertJsonMissingPath('data.0.main_category');

        $employee = $this->customerType($admin, 'WATUMISHI_WA_UMMA', 'Mtumishi wa Umma');
        $this->getJson("/api/v1/settings/loan-categories/{$loanCategory->id}")->assertOk()->assertJsonPath('data.customer_type_id', $type->id);
        $this->putJson("/api/v1/settings/loan-categories/{$loanCategory->id}", $this->payload($admin, ['loan_name' => 'BIASHARA 2', 'requires_mandate' => 'NO', 'customer_type_id' => $employee->id]))
            ->assertOk()
            ->assertJsonPath('data.customer_type.name', 'Mtumishi wa Umma');
        $this->assertFalse($loanCategory->fresh()->requires_mandate);
        $this->assertSame($employee->id, $loanCategory->fresh()->customer_category_id);

        $this->deleteJson("/api/v1/settings/loan-categories/{$loanCategory->id}")->assertOk();
        $this->assertModelMissing($loanCategory);
    }

    public function test_list_filters_by_customer_type(): void
    {
        $admin = $this->signInAdmin();
        $business = $this->customerType($admin);
        $employee = $this->customerType($admin, 'WATUMISHI_WA_UMMA', 'Mtumishi wa Umma');
        LoanCategory::factory()->forCustomerType($business)->create(['name' => 'WAJASILIAMALI']);
        LoanCategory::factory()->forCustomerType($employee)->count(2)->create(['name' => 'WATUMISHI']);

        $this->getJson('/api/v1/settings/loan-categories')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson("/api/v1/settings/loan-categories?customer_type_id={$business->id}")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer_type.name', 'Mjasiriamali/Mfanyabiashara');
        $response = $this->getJson("/api/v1/settings/loan-categories?customer_type_id={$employee->id}")->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(['Mtumishi wa Umma', 'Mtumishi wa Umma'], array_column(array_column($response->json('data'), 'customer_type'), 'name'));
    }

    public function test_loan_category_requires_a_customer_type(): void
    {
        $admin = $this->signInAdmin();

        $this->postJson('/api/v1/settings/loan-categories', array_diff_key($this->payload($admin), ['customer_type_id' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors(['customer_type_id' => 'The customer type field is required.']);
        $this->assertSame(0, LoanCategory::count());
    }

    public function test_customer_type_must_be_an_active_type_of_the_same_company(): void
    {
        $admin = $this->signInAdmin();
        $foreign = CustomerCategory::factory()->create(['company_id' => Company::factory()->create()->id]);
        $inactive = CustomerCategory::factory()->create(['company_id' => $admin->company_id, 'is_active' => false]);
        $deleted = CustomerCategory::factory()->create(['company_id' => $admin->company_id]);
        $deleted->delete();

        foreach ([$foreign->id, $inactive->id, $deleted->id, 999999, 'Mtumishi wa Umma', ''] as $invalid) {
            $this->postJson('/api/v1/settings/loan-categories', $this->payload($admin, ['customer_type_id' => $invalid]))
                ->assertUnprocessable()->assertJsonValidationErrors('customer_type_id');
        }
        $this->assertSame(0, LoanCategory::count());

        // A category may keep its customer type after that type was deactivated, but cannot move to another inactive one.
        $type = $this->customerType($admin);
        $category = LoanCategory::factory()->forCustomerType($type)->create();
        $type->update(['is_active' => false]);
        $this->putJson("/api/v1/settings/loan-categories/{$category->id}", $this->payload($admin, ['customer_type_id' => $type->id]))->assertOk();
        $this->putJson("/api/v1/settings/loan-categories/{$category->id}", $this->payload($admin, ['customer_type_id' => $inactive->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('customer_type_id');
    }

    public function test_removed_main_category_and_multi_type_keys_are_refused_and_nothing_is_stored(): void
    {
        $admin = $this->signInAdmin();
        $type = $this->customerType($admin);

        foreach (['main_category_id' => 1, 'main_id' => 1, 'customer_category_id' => $type->id, 'customer_category_ids' => [$type->id]] as $key => $value) {
            $this->postJson('/api/v1/settings/loan-categories', $this->payload($admin, [$key => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->assertSame(0, LoanCategory::count());
        $this->assertFalse(Schema::hasTable('customer_category_loan_category'));
        $this->assertFalse(Schema::hasColumn('loan_categories', 'main_category_id'));
    }

    public function test_every_loan_category_has_a_customer_type_at_database_level(): void
    {
        $admin = $this->signInAdmin();
        $category = LoanCategory::factory()->forCustomerType($this->customerType($admin))->create();

        $this->assertFalse(collect(Schema::getColumns('loan_categories'))->firstWhere('name', 'customer_category_id')['nullable']);
        $this->assertSame(0, DB::table('loan_categories')->leftJoin('customer_categories', 'customer_categories.id', '=', 'loan_categories.customer_category_id')->whereNull('customer_categories.id')->count());

        try {
            DB::table('customer_categories')->where('id', $category->customer_category_id)->delete();
            $this->fail('A customer type holding loan categories must not be deletable at database level.');
        } catch (QueryException) {
            $this->assertModelExists($category);
        }

        $this->expectException(QueryException::class);
        LoanCategory::factory()->create(['company_id' => $admin->company_id, 'customer_category_id' => null]);
    }

    public function test_customer_type_rename_is_reflected_in_loan_category_responses(): void
    {
        $admin = $this->signInAdmin();
        $type = $this->customerType($admin, 'SEKTA_BINAFSI', 'Sekta Binafsi');
        $category = LoanCategory::factory()->forCustomerType($type)->create();

        $type->update(['name' => 'Sekta Binafsi (Renamed)']);

        $this->getJson('/api/v1/settings/loan-categories')->assertOk()->assertJsonPath('data.0.customer_type.name', 'Sekta Binafsi (Renamed)');
        $this->getJson("/api/v1/settings/loan-categories/{$category->id}")->assertOk()->assertJsonPath('data.customer_type.name', 'Sekta Binafsi (Renamed)');
    }

    public function test_freeze_time_is_stored_returned_editable_and_validated(): void
    {
        $admin = $this->signInAdmin();
        $admin->company->update(['loan_freeze_days' => 5]);

        $this->postJson('/api/v1/settings/loan-categories', $this->payload($admin, ['freeze_time_days' => 30]))
            ->assertCreated()
            ->assertJsonPath('data.freeze_time_days', 30);
        $category = LoanCategory::firstWhere('name', 'BIASHARA');
        $this->assertSame(30, $category->freeze_time_days);

        $this->getJson('/api/v1/settings/loan-categories')->assertOk()->assertJsonPath('data.0.freeze_time_days', 30);
        $this->getJson("/api/v1/settings/loan-categories/{$category->id}")->assertOk()->assertJsonPath('data.freeze_time_days', 30);

        $this->putJson("/api/v1/settings/loan-categories/{$category->id}", $this->payload($admin, ['freeze_time_days' => 0]))
            ->assertOk()
            ->assertJsonPath('data.freeze_time_days', 0);
        $this->putJson("/api/v1/settings/loan-categories/{$category->id}", $this->payload($admin, ['freeze_time_days' => 12]))->assertOk();
        $this->putJson("/api/v1/settings/loan-categories/{$category->id}", $this->payload($admin))->assertOk();
        $this->assertSame(12, $category->fresh()->freeze_time_days, 'Omitting the field keeps the stored value.');

        $this->postJson('/api/v1/settings/loan-categories', $this->payload($admin, ['loan_name' => 'DEFAULTED']))->assertCreated()->assertJsonPath('data.freeze_time_days', 5);

        foreach ([-1, 366, 'abc', 1.5] as $invalid) {
            $this->putJson("/api/v1/settings/loan-categories/{$category->id}", $this->payload($admin, ['freeze_time_days' => $invalid]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('freeze_time_days');
        }
        $this->assertSame(12, $category->fresh()->freeze_time_days);
    }

    public function test_validation_rejects_disabled_formula_bad_range_and_customer_type_lists(): void
    {
        $admin = $this->signInAdmin();
        $type = $this->customerType($admin);

        $this->postJson('/api/v1/settings/loan-categories', $this->payload($admin, ['formular' => 'FLAT', 'loan_perday' => '100', 'requires_mandate' => 'MAYBE', 'customer_category_ids' => [$type->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['formular', 'loan_perday', 'requires_mandate', 'customer_category_ids']);
    }

    public function test_branch_assignment_and_isolation(): void
    {
        $admin = $this->signInAdmin();
        $loanCategory = LoanCategory::factory()->create(['company_id' => $admin->company_id]);
        $foreignBranch = Branch::factory()->create();

        $this->postJson("/api/v1/settings/loan-categories/{$loanCategory->id}/branches/{$admin->branch_id}")->assertOk()->assertJsonPath('message', 'Branch Added successfully');
        $this->assertTrue($loanCategory->branches()->whereKey($admin->branch_id)->exists());

        $this->postJson("/api/v1/settings/loan-categories/{$loanCategory->id}/branches/{$foreignBranch->id}")->assertNotFound();

        $this->deleteJson("/api/v1/settings/loan-categories/{$loanCategory->id}/branches/{$admin->branch_id}")->assertOk();
        $this->assertFalse($loanCategory->branches()->exists());

        $foreignCategory = LoanCategory::factory()->create();
        $this->putJson("/api/v1/settings/loan-categories/{$foreignCategory->id}", $this->payload($admin))->assertNotFound();

        $officer = Employee::factory()->create([
            'company_id' => $admin->company_id, 'branch_id' => $admin->branch_id,
            'role_id' => $admin->company->roles()->where('key', 'loan_officer')->value('id'),
        ]);
        $this->actingAs($officer)->postJson('/api/v1/settings/loan-categories', $this->payload($admin))->assertForbidden();
    }
}

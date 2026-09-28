<?php

namespace Tests\Feature\Api\Loans;

use App\Enums\LoanStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanCategory;
use Carbon\CarbonImmutable;
use Database\Seeders\CustomerModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CUSTOMER TYPE (1) → (many) LOAN CATEGORY → LOAN APPLICATION.
 */
class CustomerLoanHierarchyApiTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        (new CustomerModuleSeeder)->seedCompany($this->admin->company);
    }

    private function type(string $code): CustomerCategory
    {
        return CustomerCategory::where('company_id', $this->admin->company_id)->where('code', $code)->sole();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function loanCategory(string $typeCode, array $attributes = [], bool $atBranch = true): LoanCategory
    {
        $category = LoanCategory::factory()->forCustomerType($this->type($typeCode))->create($attributes + ['insurance' => 0]);
        if ($atBranch) {
            $category->branches()->attach($this->admin->branch_id);
        }

        return $category;
    }

    private function customer(?string $typeCode): Customer
    {
        return Customer::factory()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->admin->branch_id,
            'customer_category_id' => $typeCode === null ? null : $this->type($typeCode)->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function form(Customer $customer, LoanCategory $category, int $amount = 100000): array
    {
        return ['customer_id' => $customer->id, 'category_id' => $category->id, 'how_loan' => $amount, 'day' => 'weekly', 'session' => 1, 'rate' => 'SIMPLE', 'fee_status' => 'NO', 'reason' => 'BIASHARA'];
    }

    public function test_seeder_creates_the_five_customer_types_idempotently_without_main_loan_categories(): void
    {
        $seeder = new CustomerModuleSeeder;
        $seeder->seedCompany($this->admin->company);
        $seeder->seedCompany($this->admin->company);

        $this->assertSame(
            ['WATUMISHI_WA_UMMA', 'SEKTA_BINAFSI', 'WAJASIRIAMALI', 'MWANAFUNZI_CHUO', 'MSTAAFU_UMMA'],
            CustomerCategory::where('company_id', $this->admin->company_id)->orderBy('sort_order')->pluck('code')->all(),
        );
        $this->assertSame(0, DB::table('main_categories')->count());
    }

    public function test_customer_types_endpoint_feeds_the_loan_category_form_including_new_types(): void
    {
        $this->getJson('/api/v1/customer-types')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('data.2.name', 'Mjasiriamali/Mfanyabiashara');

        $new = CustomerCategory::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Mkulima', 'code' => 'MKULIMA', 'sort_order' => 99]);

        $response = $this->getJson('/api/v1/customer-types')->assertOk()->assertJsonCount(6, 'data')->assertJsonPath('data.5.name', 'Mkulima');
        $this->assertSame($new->id, $response->json('data.5.id'));
        $this->assertArrayNotHasKey('mainLoanCategory', $response->json('data.5'));
    }

    public function test_categories_endpoint_returns_only_the_active_categories_of_the_customer_type(): void
    {
        $own = $this->loanCategory('WATUMISHI_WA_UMMA', ['name' => 'NEW WATUMISHI 1']);
        $this->loanCategory('WATUMISHI_WA_UMMA', ['name' => 'NOT AT BRANCH'], atBranch: false);
        $this->loanCategory('WAJASIRIAMALI', ['name' => 'WAJASILIAMALI']);
        $employee = $this->customer('WATUMISHI_WA_UMMA');

        $this->getJson(route('api.v1.loans.categories', $employee))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.value', (string) $own->id)
            ->assertJsonPath('customer_type.name', 'Mtumishi wa Umma')
            ->assertJsonPath('eligibility.rules.loan_category_ids', [$own->id]);

        $this->getJson(route('api.v1.loans.categories', $employee))->assertOk()->assertJsonMissingPath('main_category');
        $business = $this->customer('WAJASIRIAMALI');
        $this->getJson(route('api.v1.loans.categories', $business))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.label', 'WAJASILIAMALI / 20000 - 2000000')
            ->assertJsonPath('customer_type.name', 'Mjasiriamali/Mfanyabiashara');

        // An inactive customer type offers no loan categories.
        $this->type('WATUMISHI_WA_UMMA')->update(['is_active' => false]);
        $this->getJson(route('api.v1.loans.categories', $employee))->assertOk()->assertJsonCount(0, 'data');

        $this->getJson(route('api.v1.loans.categories', $this->customer(null)))->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('customer_type', null)
            ->assertJsonPath('eligibility.allowed', false)
            ->assertJsonPath('eligibility.reasons.0', 'Assign a customer type to this customer before applying for a loan.');
    }

    public function test_apply_refuses_a_loan_category_of_another_customer_type_and_creates_nothing(): void
    {
        $this->loanCategory('WATUMISHI_WA_UMMA');
        $business = $this->loanCategory('WAJASIRIAMALI');
        $employee = $this->customer('WATUMISHI_WA_UMMA');

        $this->postJson(route('api.v1.loans.store'), $this->form($employee, $business))
            ->assertUnprocessable()
            ->assertJsonPath('errors.category_id.0', "This loan category is not available for the customer's customer type (Mtumishi wa Umma).");

        $this->type('WATUMISHI_WA_UMMA')->update(['is_active' => false]);
        $disabled = LoanCategory::where('customer_category_id', $this->type('WATUMISHI_WA_UMMA')->id)->sole();
        $this->postJson(route('api.v1.loans.store'), $this->form($employee->fresh(), $disabled))
            ->assertUnprocessable()
            ->assertJsonPath('errors.category_id.0', "This loan category is not active for the customer's customer type (Mtumishi wa Umma).");

        $this->assertSame(0, Loan::count());
    }

    public function test_apply_without_customer_type_is_refused(): void
    {
        $category = $this->loanCategory('WAJASIRIAMALI');

        $this->postJson(route('api.v1.loans.store'), $this->form($this->customer(null), $category))
            ->assertUnprocessable()
            ->assertJsonPath('errors.customer_id.0', 'Assign a customer type to this customer before applying for a loan.');
        $this->assertSame(0, Loan::count());
    }

    public function test_amount_must_be_within_the_loan_category_limits(): void
    {
        $category = $this->loanCategory('WAJASIRIAMALI', ['amount_from' => 50000, 'amount_to' => 500000]);
        $customer = $this->customer('WAJASIRIAMALI');

        foreach ([49999, 500001] as $amount) {
            $this->postJson(route('api.v1.loans.store'), $this->form($customer, $category, $amount))
                ->assertUnprocessable()
                ->assertJsonPath('errors.how_loan.0', 'Loan amount must be between 50,000 - 500,000');
        }
        $this->assertSame(0, Loan::count());

        $this->postJson(route('api.v1.loans.store'), $this->form($customer, $category, 500000))->assertCreated();
        $this->assertSame($category->id, Loan::sole()->loan_category_id);
    }

    public function test_freeze_is_still_enforced_after_the_hierarchy_check(): void
    {
        $category = $this->loanCategory('WAJASIRIAMALI', ['freeze_time_days' => 7]);
        $customer = $this->customer('WAJASIRIAMALI');
        Loan::factory()->create([
            'customer_id' => $customer->id, 'loan_category_id' => $category->id, 'status' => LoanStatus::Closed, 'early_settlement' => true,
            'disbursed_at' => now()->subDay(), 'closed_at' => now(), 'freeze_started_at' => now()->subDay(), 'freeze_days' => 7, 'frozen_until' => CarbonImmutable::now()->addDays(6)->setTime(10, 0),
        ]);

        $this->postJson(route('api.v1.loans.store'), $this->form($customer, $category))
            ->assertUnprocessable()
            ->assertJsonPath('errors.customer_id.0', fn (string $message): bool => str_starts_with($message, 'Customer fully settled the previous loan early. Re-borrowing is frozen until'));
        $this->assertSame(1, Loan::count());
    }

    public function test_manager_approval_rechecks_the_hierarchy(): void
    {
        $category = $this->loanCategory('WAJASIRIAMALI');
        $customer = $this->customer('WAJASIRIAMALI');
        $this->postJson(route('api.v1.loans.store'), $this->form($customer, $category))->assertCreated();
        $loan = Loan::sole();

        $customer->update(['customer_category_id' => $this->type('MWANAFUNZI_CHUO')->id]);

        $this->postJson(route('api.v1.loans.approve-manager', $loan), ['loan_aprove' => 100000])
            ->assertUnprocessable()
            ->assertJsonPath('errors.loan_aprove.0', "This loan category is not available for the customer's customer type (Mwanafunzi wa Chuo).");
        $this->assertSame(LoanStatus::PendingManagerApproval, $loan->fresh()->status);
    }

    public function test_customer_type_rename_is_reflected_in_loan_categories_and_other_companies_are_isolated(): void
    {
        $category = $this->loanCategory('SEKTA_BINAFSI');
        $type = $this->type('SEKTA_BINAFSI');
        $type->update(['name' => 'Sekta Binafsi (Renamed)']);

        $this->getJson("/api/v1/settings/loan-categories/{$category->id}")->assertOk()->assertJsonPath('data.customer_type.name', 'Sekta Binafsi (Renamed)');
        $this->getJson(route('api.v1.loans.categories', $this->customer('SEKTA_BINAFSI')))->assertOk()->assertJsonPath('customer_type.name', 'Sekta Binafsi (Renamed)');

        $foreign = LoanCategory::factory()->create(['company_id' => Company::factory()->create()->id]);
        $this->getJson("/api/v1/settings/loan-categories/{$foreign->id}")->assertNotFound();
        $this->getJson("/api/v1/settings/loan-categories?customer_type_id={$foreign->customer_category_id}")->assertOk()->assertJsonCount(0, 'data');
    }
}

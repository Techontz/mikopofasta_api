<?php

namespace Tests\Feature\Api\Settings;

use App\Models\Employee;
use App\Models\MasterData\Bank;
use App\Models\MasterData\DocumentType;
use App\Models\MasterData\GovernmentBody;
use App\Models\MasterData\GovernmentDepartment;
use App\Models\MasterData\MobileMoneyProvider;
use App\Services\Customers\MasterDataRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CUSTOMER_MODULE_IMPLEMENTATION.md §8.2 (cascade endpoints) and §8.9 (master data administration needs settings.manage).
 */
class MasterDataApiTest extends TestCase
{
    use RefreshDatabase;

    private function signInAs(Employee $admin, string $role): Employee
    {
        $employee = Employee::factory()->create([
            'company_id' => $admin->company_id, 'branch_id' => $admin->branch_id,
            'role_id' => $admin->company->roles()->where('key', $role)->value('id'),
        ]);
        $this->actingAs($employee);

        return $employee;
    }

    public function test_master_data_returns_every_flat_list_with_active_rows_only(): void
    {
        $this->signInAdmin();
        Bank::create(['code' => 'NMB', 'name' => 'NMB Bank', 'sort_order' => 2]);
        Bank::create(['code' => 'CRDB', 'name' => 'CRDB Bank', 'sort_order' => 1]);
        Bank::create(['code' => 'OLD', 'name' => 'Old Bank', 'is_active' => false]);
        MobileMoneyProvider::create(['code' => 'M_PESA', 'name' => 'M-Pesa', 'description' => 'Vodacom']);

        $response = $this->getJson('/api/v1/master-data')->assertOk();

        $this->assertSame(app(MasterDataRegistry::class)->flatSlugs(), array_keys($response->json('data')));
        $response->assertJsonCount(2, 'data.banks')
            ->assertJsonPath('data.banks.0.name', 'CRDB Bank')
            ->assertJsonPath('data.mobile-money-providers.0', ['id' => MobileMoneyProvider::first()->id, 'code' => 'M_PESA', 'name' => 'M-Pesa', 'description' => 'Vodacom', 'sortOrder' => 0, 'isActive' => true]);

        $this->getJson('/api/v1/master-data/banks')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/master-data/banks?includeInactive=1')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/master-data/unknown-list')->assertNotFound()->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
    }

    public function test_parented_list_returns_only_children_of_the_given_parent(): void
    {
        $admin = $this->signInAdmin();
        $treasury = GovernmentBody::create(['code' => 'HAZINA', 'name' => 'Hazina']);
        $health = GovernmentBody::create(['code' => 'AFYA', 'name' => 'Wizara ya Afya']);
        $accounts = GovernmentDepartment::create(['government_body_id' => $treasury->id, 'code' => 'HESABU', 'name' => 'Hesabu']);
        GovernmentDepartment::create(['government_body_id' => $treasury->id, 'code' => 'ZAMANI', 'name' => 'Zamani', 'is_active' => false]);
        GovernmentDepartment::create(['government_body_id' => $health->id, 'code' => 'HESABU', 'name' => 'Hesabu (Afya)']);

        $this->signInAs($admin, 'loan_officer');

        $this->getJson("/api/v1/master-data/parented/government-departments?parent_id={$treasury->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0', ['id' => $accounts->id, 'code' => 'HESABU', 'name' => 'Hesabu', 'description' => null, 'sortOrder' => 0, 'isActive' => true, 'parentId' => $treasury->id]);

        $this->getJson('/api/v1/master-data/parented/government-departments')->assertOk()->assertExactJson(['data' => []]);
        $this->getJson('/api/v1/master-data/parented/government-departments?parent_id=999999')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/master-data/parented/not-a-list?parent_id=1')->assertNotFound()->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
        $this->getJson("/api/v1/master-data/parented/banks?parent_id={$treasury->id}")->assertNotFound();

        foreach (app(MasterDataRegistry::class)->parentedSlugs() as $slug) {
            $this->getJson("/api/v1/master-data/parented/{$slug}?parent_id=1")->assertOk();
        }
    }

    public function test_registry_maps_slugs_parents_and_finds_rows(): void
    {
        $registry = app(MasterDataRegistry::class);
        $body = GovernmentBody::create(['code' => 'HAZINA', 'name' => 'Hazina']);

        $this->assertSame('government_body_id', $registry->parentColumn('government-departments'));
        $this->assertSame('private-departments', $registry->parentSlug('private-cadres'));
        $this->assertSame('private_sector_id', $registry->parentColumn('private-employers'));
        $this->assertFalse($registry->isParented('colleges'));
        $this->assertTrue($registry->isParented('courses'));
        $this->assertSame($body->id, $registry->find('government-bodies', (string) $body->id)?->id);
        $this->assertNull($registry->find('government-bodies', 'abc'));
        $this->assertNull($registry->find('nope', $body->id));
        $body->delete();
        $this->assertNull($registry->find('government-bodies', $body->id));
    }

    public function test_settings_manage_administers_lists_and_others_are_refused_before_validation(): void
    {
        $admin = $this->signInAdmin();
        $body = GovernmentBody::create(['code' => 'HAZINA', 'name' => 'Hazina']);

        $this->postJson('/api/v1/master-data/banks', ['name' => 'Equity Bank', 'sortOrder' => 4])
            ->assertCreated()->assertJsonPath('data.code', 'EQUITY_BANK')->assertJsonPath('data.isActive', true);
        $this->postJson('/api/v1/master-data/banks', ['name' => 'Equity Bank'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'VALIDATION_FAILED')->assertJsonValidationErrors('code');

        $this->postJson('/api/v1/master-data/government-departments', ['name' => 'Utawala'])->assertUnprocessable()->assertJsonValidationErrors('parentId');
        $department = $this->postJson('/api/v1/master-data/government-departments', ['name' => 'Utawala', 'parentId' => $body->id])
            ->assertCreated()->assertJsonPath('data.parentId', $body->id)->json('data');

        $this->putJson("/api/v1/master-data/government-departments/{$department['id']}", ['name' => 'Utawala Mkuu', 'code' => 'UTAWALA', 'parentId' => $body->id, 'isActive' => false])
            ->assertOk()->assertJsonPath('data.name', 'Utawala Mkuu')->assertJsonPath('data.isActive', false);

        $bank = Bank::where('code', 'EQUITY_BANK')->sole();
        $this->deleteJson("/api/v1/master-data/banks/{$bank->id}")->assertOk();
        $this->assertSoftDeleted($bank);
        $this->postJson('/api/v1/master-data/banks', ['name' => 'Equity Bank'])->assertCreated()->assertJsonPath('data.id', $bank->id);

        $kyc = DocumentType::create(['code' => 'kyc_attachment', 'name' => 'KYC Attachment']);
        $this->deleteJson("/api/v1/master-data/document-types/{$kyc->id}")->assertStatus(409)->assertJsonPath('error_code', 'CONFLICT');
        $this->deleteJson('/api/v1/master-data/banks/999999')->assertNotFound();

        $this->signInAs($admin, 'branch_manager');
        $this->postJson('/api/v1/master-data/banks', [])->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN')->assertJsonMissingPath('errors');
        $this->putJson("/api/v1/master-data/banks/{$bank->id}", [])->assertForbidden();
        $this->deleteJson("/api/v1/master-data/banks/{$bank->id}")->assertForbidden();
        $this->getJson('/api/v1/master-data/banks?includeInactive=1')->assertOk();
        $this->getJson('/api/v1/master-data/geography')->assertForbidden();
    }
}

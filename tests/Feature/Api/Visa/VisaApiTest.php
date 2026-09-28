<?php

namespace Tests\Feature\Api\Visa;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_shows_employed_customers_and_bank_account_holders(): void
    {
        $admin = $this->signInAdmin();
        $employed = Customer::factory()->create(['branch_id' => $admin->branch_id, 'work_status' => 'ent', 'bank_account_name' => 'CRDB']);
        Customer::factory()->create(['branch_id' => $admin->branch_id, 'work_status' => 'ser']);
        $otherBranch = Branch::factory()->create(['company_id' => $admin->company_id]);
        Customer::factory()->create(['branch_id' => $otherBranch->id, 'work_status' => 'ent']);

        $this->getJson('/api/v1/visa/customers')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/visa/customers?branch_id={$admin->branch_id}")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer', $employed->full_name)
            ->assertJsonPath('data.0.bank_account_name', 'CRDB');
    }

    public function test_account_name_and_password_can_be_updated_and_are_audited(): void
    {
        $admin = $this->signInAdmin();
        $customer = Customer::factory()->create(['branch_id' => $admin->branch_id, 'work_status' => 'ent']);

        $this->putJson("/api/v1/visa/customers/{$customer->id}", ['ac_name' => 'NBC', 'ac_password' => '1234'])
            ->assertOk()->assertJsonPath('message', 'Account Updated successfully');

        $customer->refresh();
        $this->assertSame('NBC', $customer->bank_account_name);
        $this->assertSame('1234', $customer->bank_password);

        $log = AuditLog::where('action', 'Customer.visa_updated')->firstOrFail();
        $this->assertStringNotContainsString('1234', json_encode($log->after));

        $this->putJson("/api/v1/visa/customers/{$customer->id}", ['ac_name' => str_repeat('x', 300)])->assertUnprocessable()->assertJsonValidationErrors('ac_name');
    }

    public function test_permission_scope_and_company_isolation(): void
    {
        $admin = $this->signInAdmin();
        $foreign = Customer::factory()->create(['work_status' => 'ent']);
        $this->putJson("/api/v1/visa/customers/{$foreign->id}", ['ac_name' => 'NBC'])->assertNotFound();
        $this->assertNull($foreign->fresh()->bank_account_name);

        $finance = Employee::factory()->create([
            'company_id' => $admin->company_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->company->roles()->where('key', 'finance')->value('id'),
        ]);
        $this->actingAs($finance);
        $this->getJson('/api/v1/visa/customers')->assertForbidden();
    }
}

<?php

namespace Tests\Feature\Api\Settings;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Settings → Payment Channels: the company's own short list of banks and networks it receives payments through — kept apart
 * from the Master Data bank list (customers' banks) and from the company's fund bank accounts.
 */
class PaymentProviderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_registers_updates_and_deletes_the_company_banks_and_networks(): void
    {
        $admin = $this->signInAdmin();

        $id = $this->postJson('/api/v1/settings/payment-providers', ['channel' => 'BANK', 'name' => 'CRDB Bank'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/settings/payment-providers', ['channel' => 'MNO', 'name' => 'M-Pesa'])->assertCreated();
        $this->postJson('/api/v1/settings/payment-providers', ['channel' => 'BANK', 'name' => 'CRDB Bank'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/settings/payment-providers', ['channel' => 'CASH', 'name' => 'Till'])->assertUnprocessable()->assertJsonValidationErrors('channel');

        $this->getJson('/api/v1/settings/payment-providers')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/settings/payment-providers/options?channel=MNO')->assertOk()->assertJsonPath('data.0.value', 'M-Pesa')->assertJsonCount(1, 'data');

        $this->putJson("/api/v1/settings/payment-providers/{$id}", ['channel' => 'BANK', 'name' => 'CRDB Bank', 'is_active' => false])->assertOk();
        $this->getJson('/api/v1/settings/payment-providers/options?channel=BANK')->assertOk()->assertJsonCount(0, 'data');

        $foreign = PaymentProvider::create(['company_id' => Company::factory()->create()->id, 'channel' => 'BANK', 'name' => 'NMB Bank']);
        $this->deleteJson("/api/v1/settings/payment-providers/{$foreign->id}")->assertNotFound();
        $this->deleteJson("/api/v1/settings/payment-providers/{$id}")->assertOk();
        $this->assertSame(1, PaymentProvider::where('company_id', $admin->company_id)->count());
    }

    public function test_finance_reads_the_active_list_but_never_changes_it(): void
    {
        $admin = $this->signInAdmin();
        PaymentProvider::create(['company_id' => $admin->company_id, 'channel' => 'BANK', 'name' => 'NMB Bank']);
        $finance = Employee::factory()->create([
            'company_id' => $admin->company_id, 'branch_id' => $admin->branch_id,
            'role_id' => $admin->company->roles()->where('key', 'finance')->value('id'),
        ]);
        $this->actingAs($finance);

        $this->getJson('/api/v1/settings/payment-providers/options?channel=BANK')->assertOk()->assertJsonPath('data.0.label', 'NMB Bank');
        $this->getJson('/api/v1/settings/payment-providers')->assertForbidden();
        $this->postJson('/api/v1/settings/payment-providers', ['channel' => 'BANK', 'name' => 'CRDB Bank'])->assertForbidden();
    }
}

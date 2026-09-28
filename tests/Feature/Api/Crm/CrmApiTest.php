<?php

namespace Tests\Feature\Api\Crm;

use App\Integrations\Sms\LogSmsGateway;
use App\Integrations\Sms\SmsGateway;
use App\Models\Branch;
use App\Models\CrmInteraction;
use App\Models\CrmTicket;
use App\Models\Customer;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmApiTest extends TestCase
{
    use RefreshDatabase;

    private LogSmsGateway $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new LogSmsGateway;
        $this->app->instance(SmsGateway::class, $this->sms);
    }

    private function employee(Employee $admin, string $role, ?Branch $branch = null): Employee
    {
        return Employee::factory()->create([
            'company_id' => $admin->company_id,
            'branch_id' => ($branch ?? $admin->branch)->id,
            'role_id' => $admin->company->roles()->where('key', $role)->value('id'),
        ]);
    }

    public function test_officer_records_calls_with_follow_up_and_sees_reminders(): void
    {
        $admin = $this->signInAdmin();
        $officer = $this->employee($admin, 'loan_officer');
        $customer = Customer::factory()->create(['branch_id' => $admin->branch_id]);
        $this->actingAs($officer);

        $this->postJson('/api/v1/crm/calls', [
            'customer_id' => $customer->id,
            'direction' => 'outgoing',
            'outcome' => 'promised_to_pay',
            'notes' => 'Atalipa kesho',
            'follow_up_date' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('message', 'Call recorded successfully')
            ->assertJsonPath('data.phone', $customer->phone)
            ->assertJsonPath('data.follow_up_status', 'due');

        $this->postJson('/api/v1/crm/calls', ['customer_id' => $customer->id, 'direction' => 'incoming', 'outcome' => 'inquiry'])->assertCreated();

        $this->getJson('/api/v1/crm/interactions?type=call')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/crm/summary')->assertOk()->assertJsonPath('data.calls_today', 2)->assertJsonPath('data.my_follow_ups_due', 1);

        $followUps = $this->getJson('/api/v1/crm/follow-ups?mine=1')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/crm/follow-ups/'.$followUps->json('data.0.id').'/complete', ['follow_up_notes' => 'Amelipa'])
            ->assertOk()->assertJsonPath('message', 'Follow-up completed successfully');

        $this->getJson('/api/v1/crm/follow-ups')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/crm/follow-ups?status=done')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/crm/follow-ups/'.$followUps->json('data.0.id').'/complete')->assertUnprocessable();
    }

    public function test_call_validation(): void
    {
        $this->signInAdmin();

        $this->postJson('/api/v1/crm/calls', ['direction' => 'sideways', 'follow_up_date' => '2000-01-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id', 'direction', 'outcome', 'follow_up_date']);
    }

    public function test_sms_goes_through_connector_and_is_logged(): void
    {
        $admin = $this->signInAdmin();
        $customer = Customer::factory()->create(['branch_id' => $admin->branch_id]);

        $this->postJson('/api/v1/crm/sms', ['customer_id' => $customer->id, 'message' => 'Kumbusho: rejesho lako ni kesho'])
            ->assertCreated()->assertJsonPath('message', 'Message sent successfully')->assertJsonPath('data.type', 'sms');

        $this->assertCount(1, $this->sms->sent);
        $this->assertSame($customer->phone, $this->sms->sent[0]['phone']);
        $this->assertDatabaseHas('sms_logs', ['customer_id' => $customer->id, 'message' => 'Kumbusho: rejesho lako ni kesho']);
        $this->assertNotNull(CrmInteraction::first()->sms_log_id);

        $this->getJson("/api/v1/crm/customers/{$customer->id}")->assertOk()
            ->assertJsonCount(1, 'data.timeline')->assertJsonCount(1, 'data.sms')
            ->assertJsonPath('data.customer.code', $customer->customer_code);
    }

    public function test_bulk_sms_by_branch_and_status(): void
    {
        $admin = $this->signInAdmin();
        Customer::factory()->count(2)->create(['branch_id' => $admin->branch_id, 'status' => 'out']);
        Customer::factory()->create(['branch_id' => $admin->branch_id, 'status' => 'open']);

        $this->postJson('/api/v1/crm/sms/bulk', ['branch_id' => (string) $admin->branch_id, 'customer_status' => 'out', 'message' => 'Tafadhali lipa deni lako'])
            ->assertCreated()->assertJsonPath('count', 2);

        $this->assertCount(2, $this->sms->sent);
        $this->assertDatabaseCount('sms_logs', 2);

        $this->postJson('/api/v1/crm/sms/bulk', ['branch_id' => 'all', 'customer_status' => 'close', 'message' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('customer_status');
    }

    public function test_customer_reports_workflow(): void
    {
        $admin = $this->signInAdmin();
        $customer = Customer::factory()->create(['branch_id' => $admin->branch_id]);

        $ticket = $this->postJson('/api/v1/crm/tickets', [
            'customer_id' => $customer->id,
            'category' => 'payment_issue',
            'channel' => 'call',
            'priority' => 'high',
            'subject' => 'Malipo hayajaonekana',
            'description' => 'Alilipa kwa M-Pesa jana lakini salio halijabadilika.',
        ])->assertCreated()->assertJsonPath('message', 'Customer report registered successfully')->json('data');

        $this->assertMatchesRegularExpression('/^CRM\d{6}$/', $ticket['ticket_number']);

        $this->putJson("/api/v1/crm/tickets/{$ticket['id']}", ['status' => 'resolved', 'priority' => 'high'])
            ->assertUnprocessable()->assertJsonValidationErrors('resolution');

        $this->putJson("/api/v1/crm/tickets/{$ticket['id']}", ['status' => 'resolved', 'priority' => 'high', 'resolution' => 'Malipo yamehamishwa kutoka suspense.'])
            ->assertOk()->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.resolver', $admin->full_name);

        $this->assertNotNull(CrmTicket::find($ticket['id'])->resolved_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CrmTicket.updated', 'auditable_id' => $ticket['id']]);
        $this->getJson('/api/v1/crm/tickets?status=pending')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/crm/tickets?status=all')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_role_without_crm_permission_is_forbidden(): void
    {
        $admin = $this->signInAdmin();
        $this->actingAs($this->employee($admin, 'teller'));

        $this->getJson('/api/v1/crm/interactions')->assertForbidden();
        $this->getJson('/api/v1/crm/summary')->assertForbidden();
        $this->postJson('/api/v1/crm/tickets', [])->assertForbidden();
    }

    public function test_branch_scope_isolation(): void
    {
        $admin = $this->signInAdmin();
        $otherBranch = Branch::factory()->create(['company_id' => $admin->company_id]);
        $officer = $this->employee($admin, 'loan_officer');
        $ownCustomer = Customer::factory()->create(['branch_id' => $admin->branch_id]);
        $otherCustomer = Customer::factory()->create(['branch_id' => $otherBranch->id]);

        CrmInteraction::create(['company_id' => $admin->company_id, 'branch_id' => $otherBranch->id, 'customer_id' => $otherCustomer->id, 'type' => 'call', 'outcome' => 'answered']);
        CrmInteraction::create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'customer_id' => $ownCustomer->id, 'type' => 'call', 'outcome' => 'answered']);

        $this->actingAs($officer);

        $this->getJson('/api/v1/crm/interactions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer.id', $ownCustomer->id);
        $this->postJson('/api/v1/crm/calls', ['customer_id' => $otherCustomer->id, 'direction' => 'outgoing', 'outcome' => 'answered'])->assertForbidden();
        $this->getJson("/api/v1/crm/customers/{$otherCustomer->id}")->assertForbidden();
        $this->postJson('/api/v1/crm/sms/bulk', ['branch_id' => (string) $otherBranch->id, 'customer_status' => 'all', 'message' => 'x'])->assertForbidden();

        $this->actingAs($admin);
        $this->getJson('/api/v1/crm/interactions')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/crm/report')->assertOk();
    }
}

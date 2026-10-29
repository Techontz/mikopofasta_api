<?php

namespace Tests\Feature\Api\Crm;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerUpdateRequest;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Customers\Concerns\BuildsCustomerModule;
use Tests\TestCase;

/**
 * CRM department (user ruling 2026-10-15): its own company-wide role that approves KYC, approves the changes branch staff make
 * to registered customers (its own changes apply directly), and handles complaints, customer requests and follow-ups on the
 * Customer Care dashboard.
 */
class CustomerCareApiTest extends TestCase
{
    use BuildsCustomerModule, RefreshDatabase;

    private Employee $admin;

    private Employee $crm;

    private Employee $officer;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->seedCustomerModule($this->admin);
        $otherBranch = Branch::factory()->create(['company_id' => $this->admin->company_id]);
        // CRM sits at HQ but serves every branch.
        $this->crm = $this->employeeWithRole($this->admin, 'crm', $otherBranch->id);
        $this->officer = $this->employeeWithRole($this->admin, 'loan_officer');
        $this->customer = Customer::factory()->create(['branch_id' => $this->admin->branch_id, 'phone' => '0754111222', 'email' => 'old@example.com', 'approval_status' => 'approved']);
    }

    public function test_a_branch_change_to_a_registered_customer_waits_for_crm_approval(): void
    {
        $this->actingAs($this->officer)->putJson("/api/v1/customers/{$this->customer->id}", ['phone' => '0754999888', 'email' => 'new@example.com'])
            ->assertStatus(202)
            ->assertJsonPath('data.changes.0.field', 'Phone')
            ->assertJsonPath('data.changes.0.old', '0754111222')
            ->assertJsonPath('data.changes.0.new', '0754999888');
        $this->assertSame('0754111222', $this->customer->fresh()->phone, 'Nothing changes until CRM approves.');

        $this->actingAs($this->officer)->putJson("/api/v1/customers/{$this->customer->id}", ['phone' => '0754000000'])
            ->assertJsonValidationErrors('customer');
        $this->actingAs($this->officer)->getJson('/api/v1/crm/customer-updates')->assertForbidden();

        $pending = $this->actingAs($this->crm)->getJson('/api/v1/crm/customer-updates')->assertOk()->json('data');
        $this->assertCount(1, $pending);
        $this->assertSame(['Phone', 'Email'], array_column($pending[0]['changes'], 'field'));
        $this->assertSame($this->officer->full_name, $pending[0]['requested_by']);

        $this->actingAs($this->crm)->postJson("/api/v1/crm/customer-updates/{$pending[0]['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(['0754999888', 'new@example.com'], [$this->customer->fresh()->phone, $this->customer->fresh()->email]);
        $this->actingAs($this->crm)->postJson("/api/v1/crm/customer-updates/{$pending[0]['id']}/approve")->assertJsonValidationErrors('status');

        $recent = $this->actingAs($this->crm)->getJson('/api/v1/crm/dashboard')->assertOk()->json('data.recent_updates');
        $this->assertSame(['Phone', '0754111222', '0754999888', $this->crm->full_name], [$recent[0]['field'], $recent[0]['old'], $recent[0]['new'], $recent[0]['by']]);
    }

    public function test_crm_changes_apply_directly_and_a_rejected_change_leaves_the_customer_as_it_was(): void
    {
        $this->actingAs($this->crm)->putJson("/api/v1/customers/{$this->customer->id}", ['email' => 'crm@example.com'])->assertOk()->assertJsonPath('data.email', 'crm@example.com');
        $this->assertDatabaseHas('customer_update_requests', ['customer_id' => $this->customer->id, 'status' => 'approved', 'requested_by' => $this->crm->id, 'decided_by' => $this->crm->id]);

        $this->actingAs($this->officer)->putJson("/api/v1/customers/{$this->customer->id}", ['phone' => '0754999888'])->assertStatus(202);
        $request = CustomerUpdateRequest::where('status', 'pending')->sole();
        $this->actingAs($this->crm)->postJson("/api/v1/crm/customer-updates/{$request->id}/reject", [])->assertJsonValidationErrors('reason');
        $this->actingAs($this->crm)->postJson("/api/v1/crm/customer-updates/{$request->id}/reject", ['reason' => 'Customer did not confirm'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame('0754111222', $this->customer->fresh()->phone);

        // A change approved after someone else changed the same field never overwrites the newer value.
        $this->actingAs($this->officer)->putJson("/api/v1/customers/{$this->customer->id}", ['phone' => '0754999888'])->assertStatus(202);
        $stale = CustomerUpdateRequest::where('status', 'pending')->sole();
        $this->actingAs($this->crm)->putJson("/api/v1/customers/{$this->customer->id}", ['phone' => '0754333444'])->assertOk();
        $this->actingAs($this->crm)->postJson("/api/v1/crm/customer-updates/{$stale->id}/approve")->assertJsonValidationErrors('customer');
        $this->assertSame('0754333444', $this->customer->fresh()->phone);
    }

    public function test_crm_approves_kyc_for_every_branch_and_the_branch_manager_no_longer_can(): void
    {
        $pending = Customer::factory()->create(['branch_id' => $this->admin->branch_id, 'approval_status' => 'pending']);

        $this->actingAs($this->employeeWithRole($this->admin, 'branch_manager'))->postJson("/api/v1/customers/{$pending->id}/approve")->assertForbidden();
        $this->actingAs($this->crm)->getJson('/api/v1/customers/pending-approval')->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($this->crm)->postJson("/api/v1/customers/{$pending->id}/approve")->assertOk()->assertJsonPath('data.approvalStatus', 'approved');
    }

    public function test_the_kyc_pipeline_counts_registrations_and_profile_changes_by_their_own_dates(): void
    {
        Customer::factory()->create(['branch_id' => $this->admin->branch_id, 'approval_status' => 'pending', 'created_at' => now()->subDays(10)]);
        $today = Customer::factory()->create(['branch_id' => $this->admin->branch_id, 'approval_status' => 'pending']);
        $this->actingAs($this->officer)->putJson("/api/v1/customers/{$this->customer->id}", ['email' => 'new@example.com'])->assertStatus(202);
        $this->actingAs($this->crm)->putJson("/api/v1/customers/{$this->customer->id}", ['email' => 'crm@example.com']);

        $pipeline = fn (): array => collect($this->actingAs($this->crm)->getJson('/api/v1/crm/dashboard')->assertOk()->json('data.kyc_pipeline'))->keyBy('key')->all();
        $first = $pipeline();
        $this->assertSame(['pending', 'approved_today', 'rejected_today', 'applications_today'], array_keys($first));
        $this->assertSame([2, 1, 3], [$first['pending']['registrations'], $first['pending']['profile_changes'], $first['pending']['count']], 'Pending stays whatever its age; registrations and re-approvals together.');
        $this->assertSame([2, 1], [$first['applications_today']['registrations'], $first['applications_today']['profile_changes']], 'Registered today (the fixture customer and one pending) and the branch change; not the old one, not CRM\'s own change.');

        $this->actingAs($this->crm)->postJson("/api/v1/customers/{$today->id}/approve")->assertOk();
        $after = $pipeline();
        $this->assertSame([2, 1], [$after['pending']['count'], $after['approved_today']['count']]);
    }

    public function test_dashboard_counts_cases_by_type_status_and_category(): void
    {
        $case = fn (string $type, string $category): int => $this->actingAs($this->crm)->postJson('/api/v1/crm/tickets', [
            'customer_id' => $this->customer->id, 'type' => $type, 'category' => $category, 'channel' => 'call', 'priority' => 'normal',
            'subject' => 'Case', 'description' => 'Details',
        ])->assertCreated()->json('data.id');

        $case('complaint', 'payment_issue');
        $waiting = $case('complaint', 'payment_issue');
        $case('request', 'account_update');
        $this->actingAs($this->crm)->putJson("/api/v1/crm/tickets/{$waiting}", ['status' => 'waiting', 'priority' => 'normal'])->assertOk()->assertJsonPath('data.status', 'waiting');
        Customer::factory()->create(['branch_id' => $this->admin->branch_id, 'approval_status' => 'pending']);
        $this->actingAs($this->officer)->putJson("/api/v1/customers/{$this->customer->id}", ['email' => 'new@example.com'])->assertStatus(202);

        $data = $this->actingAs($this->crm)->getJson('/api/v1/crm/dashboard')->assertOk()->json('data');

        $this->assertSame([2, 1, 1], [$data['cards']['open_complaints']['value'], $data['cards']['pending_requests']['value'], $data['cards']['pending_kyc']['value']]);
        $this->assertSame(['kyc' => 1, 'complaints' => 2, 'requests' => 1, 'follow_ups' => 0, 'updates' => 1], $data['badges']);
        $this->actingAs($this->crm)->getJson('/api/v1/crm/badges')->assertOk()->assertExactJson(['data' => $data['badges']]);
        $this->assertSame(3, $data['cases_by_category']['total']);
        $this->assertSame(['Payment Issue', 2, 66.7], [$data['cases_by_category']['rows'][0]['label'], $data['cases_by_category']['rows'][0]['count'], $data['cases_by_category']['rows'][0]['percent']]);
        $this->assertSame(['open' => 2, 'in_progress' => 0, 'waiting' => 1, 'resolved' => 0, 'closed' => 0], collect($data['case_status'])->pluck('count', 'status')->all());

        $this->actingAs($this->crm)->getJson('/api/v1/crm/tickets?type=request&status=pending')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->crm)->getJson('/api/v1/crm/tickets?type=complaint&status=pending')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($this->employeeWithRole($this->admin, 'teller'))->getJson('/api/v1/crm/dashboard')->assertForbidden();
    }
}

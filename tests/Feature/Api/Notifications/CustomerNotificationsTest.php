<?php

namespace Tests\Feature\Api\Notifications;

use App\Models\Customer;
use App\Services\Customers\CustomerRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Customers\Concerns\BuildsCustomerModule;
use Tests\TestCase;

/**
 * Header-bell notifications about customer activity: reviewers and CRM hear about work waiting for them, the requester
 * hears the decision, and each employee reads only their own notifications.
 */
class CustomerNotificationsTest extends TestCase
{
    use BuildsCustomerModule, RefreshDatabase;

    public function test_a_registration_notifies_crm_and_approvers_but_not_the_officer_or_unrelated_staff(): void
    {
        $admin = $this->signInAdmin();
        $crm = $this->employeeWithRole($admin, 'crm');
        $officer = $this->employeeWithRole($admin, 'loan_officer');
        $teller = $this->employeeWithRole($admin, 'teller');
        $customer = Customer::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'approval_status' => 'pending', 'employee_id' => $officer->id]);

        app(CustomerRegistrar::class)->audit($customer, 'Customer.registered', [], [], $officer);

        $this->assertSame(['New customer awaiting KYC approval'], $crm->notifications()->get()->pluck('data.title')->all());
        $this->assertSame('/customers/approvals', $crm->notifications()->first()->data['link']);
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(0, $officer->notifications()->count());
        $this->assertSame(0, $teller->notifications()->count());
    }

    public function test_the_officer_hears_the_decision_and_reads_only_their_own_notifications(): void
    {
        $admin = $this->signInAdmin();
        $officer = $this->employeeWithRole($admin, 'loan_officer');
        $crm = $this->employeeWithRole($admin, 'crm');
        $customer = Customer::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'approval_status' => 'pending', 'employee_id' => $officer->id]);

        $this->postJson("/api/v1/customers/{$customer->id}/approve")->assertOk();

        $this->actingAs($officer);
        $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.items.0.title', 'Customer registration approved')
            ->assertJsonPath('data.items.0.link', "/customers/{$customer->id}");
        $id = $officer->notifications()->value('id');

        $this->actingAs($crm)->postJson("/api/v1/notifications/{$id}/read")->assertNotFound();
        $this->actingAs($officer)->postJson("/api/v1/notifications/{$id}/read")->assertOk();
        $this->getJson('/api/v1/notifications')->assertJsonPath('data.unread_count', 0);

        $this->travel(1)->minutes();
        app(CustomerRegistrar::class)->audit($customer, 'Customer.rejected', ['reason' => 'Blurry ID'], [], $admin);
        $this->getJson('/api/v1/notifications')->assertJsonPath('data.unread_count', 1)->assertJsonPath('data.items.0.title', 'Customer registration returned');
        $this->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->getJson('/api/v1/notifications')->assertJsonPath('data.unread_count', 0);
    }
}

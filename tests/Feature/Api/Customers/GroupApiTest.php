<?php

namespace Tests\Feature\Api\Customers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Group;
use App\Models\Loan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_can_be_created_renamed_and_deleted(): void
    {
        $admin = $this->signInAdmin();

        $this->postJson('/api/v1/groups', ['group_name' => ''])->assertUnprocessable()->assertJsonValidationErrors('group_name');
        $this->postJson('/api/v1/groups', ['group_name' => 'wazuri'])->assertCreated()->assertJsonPath('message', 'Group Registered successfully');
        $group = Group::where('name', 'wazuri')->firstOrFail();
        $this->assertSame($admin->company_id, $group->company_id);

        $this->getJson('/api/v1/groups')->assertOk()->assertJsonPath('data.0.name', 'wazuri');
        $this->getJson('/api/v1/groups/options')->assertOk()->assertJsonPath('data.0.label', 'wazuri');

        $this->putJson("/api/v1/groups/{$group->id}", ['group_name' => 'WAZURI'])->assertOk()->assertJsonPath('message', 'Group Updated successfully');
        $this->assertSame('WAZURI', $group->fresh()->name);

        $this->deleteJson("/api/v1/groups/{$group->id}")->assertOk()->assertJsonPath('message', 'Group Deleted successfully');
        $this->assertModelMissing($group);
    }

    public function test_group_customer_list_shows_member_loans_with_branch_filter(): void
    {
        $admin = $this->signInAdmin();
        $otherBranch = Branch::factory()->create(['company_id' => $admin->company_id]);
        $group = Group::create(['company_id' => $admin->company_id, 'name' => 'wazuri']);
        $member = Customer::factory()->create(['branch_id' => $admin->branch_id, 'group_id' => $group->id]);
        Loan::factory()->create(['customer_id' => $member->id, 'total_payable' => 408000, 'restoration' => 136000]);
        $loanMember = Customer::factory()->create(['branch_id' => $otherBranch->id]);
        Loan::factory()->create(['customer_id' => $loanMember->id, 'group_id' => $group->id]);
        Loan::factory()->create(['customer_id' => Customer::factory()->create(['branch_id' => $admin->branch_id])->id]);

        $this->getJson("/api/v1/groups/{$group->id}")
            ->assertOk()
            ->assertJsonPath('group.name', 'wazuri')
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['customer_name' => $member->full_name, 'total_loan' => 408000, 'remain' => 408000]);

        $this->getJson("/api/v1/groups/{$group->id}?branch_id={$otherBranch->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer_id', $loanMember->id);

        $officer = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'role_id' => $admin->company->roles()->where('key', 'loan_officer')->value('id')]);
        $this->actingAs($officer)->getJson("/api/v1/groups/{$group->id}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_foreign_groups_and_roles_without_permission_are_refused(): void
    {
        $admin = $this->signInAdmin();
        $foreign = Group::create(['company_id' => Company::factory()->create()->id, 'name' => 'other']);

        $this->getJson("/api/v1/groups/{$foreign->id}")->assertNotFound();
        $this->deleteJson("/api/v1/groups/{$foreign->id}")->assertNotFound();

        $hr = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'role_id' => $admin->company->roles()->where('key', 'hr')->value('id')]);
        $this->actingAs($hr)->getJson('/api/v1/groups')->assertForbidden();
        $this->postJson('/api/v1/groups', ['group_name' => 'x'])->assertForbidden();
    }
}

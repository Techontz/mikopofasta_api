<?php

namespace Tests\Feature\Api\Customers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Loan;
use App\Models\LoanCategory;
use App\Models\SmsLog;
use App\Services\AccessControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Customers\Concerns\BuildsCustomerModule;
use Tests\TestCase;

/**
 * All Customer list, profile endpoints, update, approvals, permissions and the API error contract (§8.9, §8.10).
 */
class CustomerApiTest extends TestCase
{
    use BuildsCustomerModule, RefreshDatabase;

    public function test_role_matrix_for_customer_permissions(): void
    {
        $admin = $this->signInAdmin();
        $access = app(AccessControl::class);
        $matrix = [
            'super_admin' => [true, true, true, true],
            'admin' => [true, true, true, true],
            'branch_manager' => [true, true, true, true],
            'loan_officer' => [true, true, false, false],
            'finance' => [true, false, false, false],
            'credit_officer' => [true, false, false, false],
            'zone_manager' => [true, false, false, false],
            'hr' => [false, false, false, false],
            'teller' => [false, false, false, false],
        ];

        foreach ($matrix as $role => $expected) {
            $employee = $this->employeeWithRole($admin, $role)->load('role.permissions');
            $actual = array_map(fn (string $permission): bool => $access->can($employee, $permission), ['customers.view', 'customers.manage', 'customers.approve', 'customers.assign_officer']);
            $this->assertSame($expected, $actual, "Role {$role}");
        }
    }

    public function test_list_is_paginated_filtered_searched_and_branch_scoped(): void
    {
        $admin = $this->signInAdmin();
        $other = Branch::factory()->create(['company_id' => $admin->company_id]);
        $category = CustomerCategory::create(['company_id' => $admin->company_id, 'key' => 'k', 'name' => 'Type', 'is_active' => true, 'required_documents' => [], 'form_schema' => []]);
        $asha = Customer::factory()->create(['branch_id' => $admin->branch_id, 'first_name' => 'ASHA', 'last_name' => 'HAMISI', 'phone' => '0754123456', 'kyc_status' => 'completed', 'approval_status' => 'pending', 'customer_category_id' => $category->id]);
        $foreign = Customer::factory()->create(['branch_id' => $other->id, 'kyc_status' => 'incomplete', 'account_status' => 'frozen']);
        Customer::factory()->count(3)->create(['branch_id' => $admin->branch_id]);
        Customer::factory()->create();
        $deleted = Customer::factory()->create(['branch_id' => $admin->branch_id]);
        $deleted->delete();

        $this->getJson('/api/v1/customers?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta', ['currentPage' => 2, 'lastPage' => 3, 'perPage' => 2, 'total' => 5]);

        $this->getJson('/api/v1/customers?search=asha hamisi')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $asha->id)->assertJsonPath('data.0.branchName', $admin->branch->name);
        $this->getJson('/api/v1/customers?search=0754123456')->assertOk()->assertJsonPath('data.0.id', $asha->id);
        $this->getJson("/api/v1/customers?search={$asha->customer_number}")->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/customers?kyc_status=incomplete')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $foreign->id);
        $this->getJson('/api/v1/customers?status=frozen')->assertOk()->assertJsonPath('data.0.id', $foreign->id)->assertJsonPath('data.0.status', 'frozen');
        $this->getJson('/api/v1/customers?approval_status=pending')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/customers?branch_id={$other->id}")->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/customers?customer_category_id={$category->id}")->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/customers?loan_eligible=0')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $foreign->id);
        $this->getJson('/api/v1/customers?loan_eligible=1')->assertOk()->assertJsonPath('meta.total', 4);
        $this->getJson('/api/v1/customers?include_deleted=1')->assertOk()->assertJsonPath('meta.total', 6);

        $officer = $this->employeeWithRole($admin, 'loan_officer');
        $this->actingAs($officer)->getJson('/api/v1/customers')->assertOk()->assertJsonPath('meta.total', 4);
        $this->actingAs($officer)->getJson("/api/v1/customers/{$foreign->id}")->assertNotFound()->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
        $this->actingAs($officer)->getJson("/api/v1/customers/{$asha->id}")->assertOk()->assertJsonPath('data.customerNumber', $asha->customer_number);
    }

    public function test_error_contract_unauthenticated_forbidden_missing_and_invalid(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/customers', [])->assertJsonPath('error_code', 'UNAUTHENTICATED')->assertUnauthorized();

        $this->actingAs($this->employeeWithRole($admin, 'hr'))->getJson('/api/v1/customers')->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');
        $this->actingAs($admin)->getJson('/api/v1/customers/999999')->assertNotFound()->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
        $this->postJson('/api/v1/customers', [])->assertUnprocessable()->assertJsonPath('error_code', 'VALIDATION_FAILED')->assertJsonStructure(['message', 'error_code', 'errors' => ['firstName']]);
    }

    public function test_without_customers_manage_every_write_is_forbidden_before_validation(): void
    {
        Storage::fake('local');
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $customerId = $this->postJson('/api/v1/customers', $this->registrationPayload($admin))->json('data.id');

        $this->actingAs($this->employeeWithRole($admin, 'credit_officer'));
        $this->postJson('/api/v1/customers', [])->assertForbidden();
        // Correcting details is customers.edit, which every role that views customers holds (2026-09-18).
        $this->putJson("/api/v1/customers/{$customerId}", ['firstName' => ''])->assertUnprocessable()->assertJsonValidationErrors('firstName');
        $this->post("/api/v1/customers/{$customerId}/documents", [], ['Accept' => 'application/json'])->assertForbidden();
        $this->post("/api/v1/customers/{$customerId}/face-verify", [], ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson("/api/v1/customers/{$customerId}/next-of-kin", [])->assertForbidden();
        $this->postJson("/api/v1/customers/{$customerId}/guarantors", [])->assertForbidden();
        $this->postJson("/api/v1/customers/{$customerId}/notes", [])->assertForbidden();
        $this->postJson('/api/v1/customer-drafts', [])->assertForbidden();
        $this->getJson('/api/v1/customers/registration-options')->assertOk();
        $this->getJson("/api/v1/customers/{$customerId}")->assertOk();
    }

    public function test_branch_scope_on_register_documents_and_face(): void
    {
        Storage::fake('local');
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $otherBranch = Branch::factory()->create(['company_id' => $admin->company_id]);
        $foreignCustomerId = $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['branchId' => $otherBranch->id]))->assertCreated()->json('data.id');

        $officer = $this->employeeWithRole($admin, 'loan_officer');
        $this->actingAs($officer)->postJson('/api/v1/customers', ['branchId' => $otherBranch->id])->assertForbidden()->assertJsonPath('message', 'You do not have access to this branch.');
        $this->post("/api/v1/customers/{$foreignCustomerId}/documents", ['documentType' => 'kyc_attachment', 'file' => UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')], ['Accept' => 'application/json'])->assertNotFound();
        $this->post("/api/v1/customers/{$foreignCustomerId}/face-verify", [], ['Accept' => 'application/json'])->assertNotFound();
        $this->putJson("/api/v1/customers/{$foreignCustomerId}", [])->assertNotFound();
    }

    public function test_registration_options_lock_the_branch_without_view_all(): void
    {
        $admin = $this->signInAdmin();
        $headOffice = Branch::factory()->create(['company_id' => $admin->company_id, 'is_head_office' => true]);
        $other = Branch::factory()->create(['company_id' => $admin->company_id]);
        $officer = $this->employeeWithRole($admin, 'loan_officer');
        $colleague = $this->employeeWithRole($admin, 'loan_officer');

        $options = $this->getJson('/api/v1/customers/registration-options')->assertOk()
            ->assertJsonPath('data.lockedBranchId', null)
            ->assertJsonPath('data.currentEmployeeId', $admin->id)
            ->assertJsonPath('data.canAssignOfficer', true)
            ->json('data');
        $this->assertEqualsCanonicalizing([$admin->branch_id, $other->id], array_column($options['branches'], 'id'));
        $this->assertNotContains($headOffice->id, array_column($options['branches'], 'id'));
        $this->assertContains($colleague->id, array_column($options['officers'], 'id'));

        $this->actingAs($officer)->getJson('/api/v1/customers/registration-options')->assertOk()
            ->assertJsonPath('data.lockedBranchId', $admin->branch_id)
            ->assertJsonPath('data.branches', [['id' => $admin->branch_id, 'name' => $admin->branch->name]])
            ->assertJsonPath('data.officers', [['id' => $officer->id, 'name' => $officer->full_name, 'branchId' => $admin->branch_id]])
            ->assertJsonPath('data.canAssignOfficer', false);
    }

    public function test_update_changes_only_sent_fields_and_rechecks_related_rules(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $type = $this->type($admin, 'WAJASIRIAMALI');
        $payload = $this->registrationPayload($admin, [
            'customerCategoryId' => $type->id,
            'dynamicFormData' => ['sekta' => $this->ids['businessSector'], 'aina' => $this->ids['businessType'], 'jina_biashara' => 'Duka', 'mapato' => 500000, 'mahali_biashara' => 'Soko'],
        ]);
        $customerId = $this->postJson('/api/v1/customers', $payload)->assertCreated()->json('data.id');
        $existing = $this->postJson('/api/v1/customers', $this->registrationPayload($admin, ['phone' => '0754777000']))->json('data.id');

        $this->putJson("/api/v1/customers/{$customerId}", ['firstName' => 'Mwanaisha', 'phone' => '0754777000', 'regionId' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone')
            ->assertJsonPath('errors.regionId.0', 'Region is required.')
            ->assertJsonMissingValidationErrors(['dynamicFormData.jina_biashara', 'idTypeId']);

        $this->putJson("/api/v1/customers/{$customerId}", ['firstName' => 'Mwanaisha', 'phone' => $payload['phone'], 'dynamicFormData' => ['sekta' => $this->ids['businessSector'], 'aina' => $this->ids['businessType'], 'jina_biashara' => 'Duka Jipya', 'mapato' => 600000, 'mahali_biashara' => 'Soko'], 'paymentMethod' => 'mno', 'mobileMoneyProviderId' => $this->ids['mpesa'], 'walletNumber' => '0754000000'])
            ->assertOk()
            ->assertJsonPath('data.firstName', 'Mwanaisha')
            ->assertJsonPath('data.lastName', 'Hamisi')
            ->assertJsonPath('data.dynamicFormData.jina_biashara', 'Duka Jipya')
            ->assertJsonPath('data.paymentMethod', 'mno')
            ->assertJsonPath('data.mobileMoneyProvider', 'M-Pesa')
            ->assertJsonPath('data.regionId', $this->ids['region']);

        $this->putJson("/api/v1/customers/{$customerId}", ['customerCategoryId' => $this->type($admin, 'MSTAAFU_UMMA')->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['dynamicFormData.makazi']);

        $officer = $this->employeeWithRole($admin, 'loan_officer');
        $this->actingAs($officer)->putJson("/api/v1/customers/{$customerId}", ['nextOfKin' => [['name' => 'Kin', 'relationship' => 'parent', 'phone' => '0754000123']]])
            ->assertOk()->assertJsonCount(1, 'data.nextOfKin')->assertJsonPath('data.nextOfKin.0.relationship', 'parent');
        $this->actingAs($officer)->putJson("/api/v1/customers/{$existing}", ['employeeId' => $admin->id])->assertOk();
        $this->actingAs($officer)->putJson("/api/v1/customers/{$existing}", ['employeeId' => $this->employeeWithRole($admin, 'loan_officer')->id])
            ->assertUnprocessable()->assertJsonValidationErrors('employeeId');
    }

    public function test_admin_can_fill_in_an_old_record_one_field_at_a_time(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $legacy = Customer::factory()->create([
            'branch_id' => $admin->branch_id, 'first_name' => 'MARKO', 'last_name' => 'VUKARI',
            'gender' => null, 'date_of_birth' => null, 'customer_category_id' => null, 'region_id' => null,
        ]);

        $this->putJson("/api/v1/customers/{$legacy->id}", ['gender' => 'male', 'dob' => '1980-01-02'])
            ->assertOk()
            ->assertJsonPath('data.gender', 'male')
            ->assertJsonPath('data.dob', '1980-01-02')
            ->assertJsonPath('data.firstName', 'MARKO');

        $this->putJson("/api/v1/customers/{$legacy->id}", ['lastName' => ''])->assertUnprocessable()->assertJsonValidationErrors('lastName');
        $this->assertSame('VUKARI', $legacy->fresh()->last_name);
    }

    public function test_staff_who_view_customers_can_edit_details_but_not_move_them(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $customer = Customer::factory()->create(['branch_id' => $admin->branch_id, 'first_name' => 'MARKO', 'last_name' => 'VUKARI', 'gender' => null]);
        $otherBranch = Branch::factory()->create(['company_id' => $admin->company_id]);

        // Zone managers hold customers.edit too, but only reach customers of their zone's branches (AccessControl scope).
        foreach (['finance', 'credit_officer', 'branch_manager', 'loan_officer'] as $role) {
            $employee = $this->employeeWithRole($admin, $role);
            $this->actingAs($employee)->getJson('/api/v1/customers/registration-options')->assertOk();
            $this->actingAs($employee)->putJson("/api/v1/customers/{$customer->id}", ['middleName' => strtoupper($role)])
                ->assertOk()->assertJsonPath('data.middleName', strtoupper($role));
        }

        $finance = $this->employeeWithRole($admin, 'finance');
        $this->actingAs($finance)->putJson("/api/v1/customers/{$customer->id}", ['branchId' => $otherBranch->id])
            ->assertUnprocessable()->assertJsonPath('errors.branchId.0', 'Only staff who register customers can move a customer to another branch.');
        $this->actingAs($finance)->putJson("/api/v1/customers/{$customer->id}", ['employeeId' => $admin->id])
            ->assertUnprocessable()->assertJsonValidationErrors('employeeId');
        $this->assertSame($admin->branch_id, $customer->fresh()->branch_id);

        $this->actingAs($this->employeeWithRole($admin, 'teller'))->putJson("/api/v1/customers/{$customer->id}", ['middleName' => 'X'])->assertForbidden();
    }

    public function test_approve_reject_and_resubmit(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $customerId = $this->postJson('/api/v1/customers', $this->registrationPayload($admin))->json('data.id');
        $officer = $this->employeeWithRole($admin, 'loan_officer');
        $manager = $this->employeeWithRole($admin, 'branch_manager');

        $this->actingAs($officer)->getJson('/api/v1/customers/pending-approval')->assertForbidden();
        $this->actingAs($officer)->postJson("/api/v1/customers/{$customerId}/approve")->assertForbidden();

        $this->actingAs($manager)->getJson('/api/v1/customers/pending-approval')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $customerId);
        $this->postJson("/api/v1/customers/{$customerId}/reject", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/customers/{$customerId}/reject", ['reason' => 'ID photo is unreadable'])
            ->assertOk()->assertJsonPath('data.approvalStatus', 'rejected')->assertJsonPath('data.rejectionReason', 'ID photo is unreadable');
        $this->postJson("/api/v1/customers/{$customerId}/approve")->assertStatus(409);

        $this->actingAs($officer)->postJson("/api/v1/customers/{$customerId}/resubmit")->assertOk()->assertJsonPath('data.approvalStatus', 'pending')->assertJsonPath('data.rejectionReason', null);

        $this->actingAs($manager)->postJson("/api/v1/customers/{$customerId}/approve")
            ->assertOk()->assertJsonPath('data.approvalStatus', 'approved')->assertJsonPath('data.approvedBy', $manager->id);
        $this->assertNotNull(Customer::findOrFail($customerId)->approved_at);

        $this->getJson("/api/v1/customers/{$customerId}/audit-trail")->assertOk()->assertJsonFragment(['action' => 'Customer.approved'])->assertJsonFragment(['action' => 'Customer.rejected']);
        $this->getJson("/api/v1/customers/{$customerId}/timeline")->assertOk()->assertJsonFragment(['type' => 'registered'])->assertJsonFragment(['title' => 'Approved']);
    }

    public function test_profile_next_of_kin_guarantors_notes_overview_and_kyc_status(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $customerId = $this->postJson('/api/v1/customers', $this->registrationPayload($admin))->json('data.id');

        $this->postJson("/api/v1/customers/{$customerId}/next-of-kin", ['name' => '', 'relationship' => 'boss'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'relationship', 'phone']);
        $kinId = $this->postJson("/api/v1/customers/{$customerId}/next-of-kin", ['name' => 'Mama', 'relationship' => 'parent', 'phone' => '0754000321', 'address' => 'Kigoma'])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/customers/{$customerId}/next-of-kin")->assertOk()->assertJsonCount(2, 'data');
        $this->deleteJson("/api/v1/customers/{$customerId}/next-of-kin/{$kinId}")->assertOk();

        $guarantorId = $this->postJson("/api/v1/customers/{$customerId}/guarantors", ['name' => 'Peter', 'phone' => '0754000322', 'relationship' => 'friend', 'nidaNumber' => '1980', 'occupation' => 'Dereva'])
            ->assertCreated()->assertJsonPath('data.occupation', 'Dereva')->json('data.id');
        $this->getJson("/api/v1/customers/{$customerId}/guarantors")->assertOk()->assertJsonPath('data.0.nidaNumber', '1980');
        $this->deleteJson("/api/v1/customers/{$customerId}/guarantors/{$guarantorId}")->assertOk();

        $this->postJson("/api/v1/customers/{$customerId}/notes", ['body' => ''])->assertUnprocessable();
        $this->postJson("/api/v1/customers/{$customerId}/notes", ['body' => 'Called the customer.'])->assertCreated()->assertJsonPath('data.createdByName', $admin->full_name);
        $this->getJson("/api/v1/customers/{$customerId}/notes")->assertOk()->assertJsonPath('data.0.body', 'Called the customer.');

        $this->getJson("/api/v1/customers/{$customerId}/overview")->assertOk()
            ->assertJsonPath('data.loans.total', 0)
            ->assertJsonPath('data.counts', ['documents' => 0, 'notes' => 1, 'guarantors' => 0, 'nextOfKin' => 1, 'faceScans' => 0]);

        $this->getJson("/api/v1/customers/{$customerId}/kyc-status")->assertOk()
            ->assertJsonPath('data.items.0', ['key' => 'identity_document', 'label' => 'Identity document', 'required' => true, 'complete' => true])
            ->assertJsonPath('data.items.3.key', 'face_verification');
    }

    public function test_legacy_actions_eligibility_mark_sms_balance_and_delete(): void
    {
        $admin = $this->signInAdmin();
        $category = LoanCategory::factory()->create(['company_id' => $admin->company_id]);
        $customer = Customer::factory()->create(['branch_id' => $admin->branch_id, 'customer_category_id' => $category->customer_category_id]);

        $this->getJson("/api/v1/customers/{$customer->id}/eligibility")->assertOk()->assertJsonPath('data.kyc_complete', true)->assertJsonPath('data.eligible', true);
        $customer->update(['kyc_status' => 'incomplete']);
        $this->getJson("/api/v1/customers/{$customer->id}/eligibility")->assertOk()->assertJsonPath('data.eligible', false);

        $this->postJson("/api/v1/customers/{$customer->id}/mark")->assertOk()->assertJsonPath('message', 'Customer Marked successfully');
        $this->postJson("/api/v1/customers/{$customer->id}/sms", ['message' => 'Habari'])->assertOk();
        $this->assertSame(1, SmsLog::where('customer_id', $customer->id)->count());

        Loan::factory()->create(['customer_id' => $customer->id]);
        $this->getJson("/api/v1/customers/{$customer->id}/balance")->assertOk()->assertJsonStructure(['data' => ['remain_loan', 'salary_advance', 'penalty', 'loan_fee', 'total', 'remain_cash']]);
        $this->getJson("/api/v1/customers/{$customer->id}/overview")->assertOk()->assertJsonPath('data.loans.total', 1);
        $this->deleteJson("/api/v1/customers/{$customer->id}")->assertUnprocessable();

        $free = Customer::factory()->create(['branch_id' => $admin->branch_id]);
        $this->deleteJson("/api/v1/customers/{$free->id}")->assertOk();
        $this->assertSoftDeleted($free);
        $this->getJson("/api/v1/customers/{$free->id}")->assertOk()->assertJsonPath('data.deletedAt', fn ($value) => $value !== null);
    }
}

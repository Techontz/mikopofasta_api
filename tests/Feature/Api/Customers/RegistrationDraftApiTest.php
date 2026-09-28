<?php

namespace Tests\Feature\Api\Customers;

use App\Models\Branch;
use App\Models\CustomerRegistrationDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Customers\Concerns\BuildsCustomerModule;
use Tests\TestCase;

/**
 * Saved registrations (§8.6 server side, IMPLEMENTATION §3.2).
 */
class RegistrationDraftApiTest extends TestCase
{
    use BuildsCustomerModule, RefreshDatabase;

    public function test_a_draft_is_created_updated_resumed_and_marked_submitted(): void
    {
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $payload = $this->registrationPayload($admin, [
            'customerCategoryId' => $this->type($admin, 'MWANAFUNZI_CHUO')->id,
            'dynamicFormData' => ['chuo' => $this->ids['college'], 'level' => 'Shahada (Degree)'],
            'paymentMethod' => 'mno', 'mobileMoneyProviderId' => $this->ids['mpesa'], 'walletNumber' => '0754000000',
        ]);

        $created = $this->postJson('/api/v1/customer-drafts', ['branchId' => $admin->branch_id, 'label' => 'Asha Hamisi', 'phone' => $payload['phone'], 'step' => 1, 'payload' => $payload])
            ->assertCreated()
            ->assertJsonPath('data.label', 'Asha Hamisi')
            ->assertJsonPath('data.step', 1)
            ->assertJsonPath('data.createdById', $admin->id)
            ->assertJsonPath('data.createdByName', $admin->full_name)
            ->assertJsonPath('data.submittedAt', null);
        $draftId = $created->json('data.id');

        $this->postJson('/api/v1/customer-drafts', ['id' => $draftId, 'branchId' => $admin->branch_id, 'label' => 'Asha Hamisi', 'step' => 2, 'payload' => $payload])
            ->assertOk()->assertJsonPath('data.step', 2);
        $this->assertSame(1, CustomerRegistrationDraft::count());

        $this->getJson('/api/v1/customer-drafts')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.payload');
        $resumed = $this->getJson("/api/v1/customer-drafts/{$draftId}")->assertOk()->assertJsonPath('data.step', 2)->json('data.payload');
        $this->assertEquals($payload, $resumed);

        $customerId = $this->postJson('/api/v1/customers', $this->registrationPayload($admin))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/customer-drafts/{$draftId}/submitted", [])->assertUnprocessable()->assertJsonValidationErrors('customerId');
        $this->postJson("/api/v1/customer-drafts/{$draftId}/submitted", ['customerId' => $customerId])->assertOk()->assertJsonPath('data.customerId', $customerId);
        $this->assertNotNull(CustomerRegistrationDraft::findOrFail($draftId)->submitted_at);

        $this->getJson('/api/v1/customer-drafts')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/customer-drafts', ['id' => $draftId, 'branchId' => $admin->branch_id, 'label' => 'Asha', 'payload' => $payload])
            ->assertStatus(409)->assertJsonPath('message', 'This registration has already been submitted.')->assertJsonPath('error_code', 'CONFLICT');
    }

    public function test_old_shape_payloads_are_stored_as_sent(): void
    {
        $admin = $this->signInAdmin();
        $payload = ['firstName' => 'Asha', 'dynamicFormData' => [], 'dependentsCount' => '2', 'nextOfKin' => null];

        $id = $this->postJson('/api/v1/customer-drafts', ['branchId' => $admin->branch_id, 'label' => 'Asha', 'step' => 3, 'payload' => $payload])->assertCreated()->json('data.id');

        $this->getJson("/api/v1/customer-drafts/{$id}")->assertOk()->assertJsonPath('data.payload.dependentsCount', '2')->assertJsonPath('data.step', 3);
        $this->postJson('/api/v1/customer-drafts', ['id' => $id, 'branchId' => $admin->branch_id, 'label' => 'Asha', 'payload' => $payload])->assertOk();
    }

    public function test_draft_validation_and_branch_rules(): void
    {
        $admin = $this->signInAdmin();

        $this->postJson('/api/v1/customer-drafts', ['label' => 'Asha', 'payload' => []])
            ->assertUnprocessable()->assertJsonPath('errors.branchId.0', 'Select a branch before saving a draft.');
        $this->postJson('/api/v1/customer-drafts', ['branchId' => $admin->branch_id, 'label' => str_repeat('a', 161), 'step' => 21])
            ->assertUnprocessable()->assertJsonValidationErrors(['label', 'step', 'payload']);
        $this->postJson('/api/v1/customer-drafts', ['branchId' => 999999, 'label' => 'x', 'payload' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('branchId');

        $otherBranch = Branch::factory()->create(['company_id' => $admin->company_id]);
        $officer = $this->employeeWithRole($admin, 'loan_officer');
        $this->actingAs($officer)->postJson('/api/v1/customer-drafts', ['branchId' => $otherBranch->id, 'label' => 'x', 'payload' => []])->assertForbidden();
    }

    public function test_another_officers_draft_cannot_be_overwritten_or_discarded_and_own_drafts_list_first(): void
    {
        $admin = $this->signInAdmin();
        $officer = $this->employeeWithRole($admin, 'loan_officer');
        $colleague = $this->employeeWithRole($admin, 'loan_officer');
        $foreignOfficer = $this->employeeWithRole($admin, 'loan_officer', Branch::factory()->create(['company_id' => $admin->company_id])->id);

        $colleagueDraft = $this->actingAs($colleague)->postJson('/api/v1/customer-drafts', ['branchId' => $admin->branch_id, 'label' => 'Colleague', 'payload' => ['a' => 1]])->assertCreated()->json('data.id');
        $this->travel(1)->minutes();
        $ownDraft = $this->actingAs($officer)->postJson('/api/v1/customer-drafts', ['branchId' => $admin->branch_id, 'label' => 'Mine', 'payload' => []])->assertCreated()->json('data.id');
        $this->travel(1)->minutes();
        $this->actingAs($colleague)->postJson('/api/v1/customer-drafts', ['id' => $colleagueDraft, 'branchId' => $admin->branch_id, 'label' => 'Colleague newer', 'payload' => []])->assertOk();

        $this->actingAs($officer)->getJson('/api/v1/customer-drafts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ownDraft)
            ->assertJsonPath('data.0.isOwn', true)
            ->assertJsonPath('data.1.id', $colleagueDraft)
            ->assertJsonPath('data.1.createdByName', $colleague->full_name);

        $this->actingAs($officer)->postJson('/api/v1/customer-drafts', ['id' => $colleagueDraft, 'branchId' => $admin->branch_id, 'label' => 'Hijack', 'payload' => []])
            ->assertForbidden()->assertJsonPath('message', 'This draft belongs to another officer.');
        $this->actingAs($officer)->postJson('/api/v1/customer-drafts', ['id' => $colleagueDraft])
            ->assertForbidden();
        $this->actingAs($officer)->deleteJson("/api/v1/customer-drafts/{$colleagueDraft}")->assertForbidden()->assertJsonPath('message', 'This draft belongs to another officer.');
        $this->actingAs($officer)->getJson("/api/v1/customer-drafts/{$colleagueDraft}")->assertOk();

        $this->actingAs($foreignOfficer)->getJson('/api/v1/customer-drafts')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($foreignOfficer)->getJson("/api/v1/customer-drafts/{$colleagueDraft}")->assertNotFound();

        $this->actingAs($officer)->deleteJson("/api/v1/customer-drafts/{$ownDraft}")->assertOk()->assertJsonPath('message', 'Draft discarded.');
        $this->assertNull(CustomerRegistrationDraft::find($ownDraft));
    }

    public function test_drafts_need_customers_manage_before_validation(): void
    {
        $admin = $this->signInAdmin();
        $this->actingAs($this->employeeWithRole($admin, 'credit_officer'));

        $this->postJson('/api/v1/customer-drafts', [])->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');
        $this->getJson('/api/v1/customer-drafts')->assertForbidden();
    }
}

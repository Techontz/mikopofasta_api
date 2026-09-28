<?php

namespace Tests\Feature\Api\Settings;

use App\Models\CustomerCategory;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CUSTOMER_MODULE_IMPLEMENTATION.md §8.10 — error envelope { message, error_code, errors? } for every api/* error.
 */
class ApiErrorEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_get_401_with_error_code(): void
    {
        $this->getJson('/api/v1/master-data')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.', 'error_code' => 'UNAUTHENTICATED']);
    }

    public function test_validation_forbidden_not_found_and_conflict_codes(): void
    {
        $admin = $this->signInAdmin();

        $this->postJson('/api/v1/master-data/banks', ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['message', 'error_code', 'errors' => ['name']]);

        $this->getJson('/api/v1/customer-categories/999999')
            ->assertNotFound()->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND')->assertJsonMissingPath('errors');
        $this->getJson('/api/v1/no-such-endpoint')->assertNotFound()->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');

        $teller = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'role_id' => $admin->company->roles()->where('key', 'teller')->value('id')]);
        $this->actingAs($teller)->getJson('/api/v1/settings/roles')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN')
            ->assertJsonPath('message', 'You do not have permission to perform this action.');

        $this->actingAs($admin);
        $kyc = $this->postJson('/api/v1/master-data/document-types', ['code' => 'kyc_attachment', 'name' => 'KYC Attachment'])->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/master-data/document-types/{$kyc}")->assertStatus(409)->assertJsonPath('error_code', 'CONFLICT');

        $this->assertSame(0, CustomerCategory::count());
    }
}

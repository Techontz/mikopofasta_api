<?php

namespace Tests\Feature\Api\Hrm;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Enhancement §12.1 (employee CV, certificates, contract) and §12.2 (employee KYC approval and locked personal details).
 */
class EmployeeKycAndDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    private Employee $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->hr = $this->staff('hr');
    }

    public function test_a_new_employee_waits_for_kyc_and_another_hr_user_approves_it(): void
    {
        $id = $this->actingAs($this->hr)->postJson('/api/v1/hrm/staff', $this->payload())->assertCreated()->json('data.id');
        $employee = Employee::findOrFail($id);
        $this->assertSame('pending', $employee->kyc_status);

        $this->actingAs($this->hr)->postJson("/api/v1/hrm/staff/{$this->hr->id}/kyc/approve")->assertForbidden();
        $this->actingAs($this->staff('loan_officer'))->postJson("/api/v1/hrm/staff/{$id}/kyc/approve")->assertForbidden();
        $this->actingAs($this->hr)->postJson("/api/v1/hrm/staff/{$id}/kyc/approve")->assertOk()->assertJsonPath('data.kyc_status', 'approved');
        $this->assertTrue(AuditLog::where('action', 'Employee.kyc_approved')->where('auditable_id', $id)->where('employee_id', $this->hr->id)->exists());
    }

    public function test_approved_details_change_only_by_another_hr_user_with_a_reason_and_are_audited(): void
    {
        $this->hr->forceFill(['kyc_status' => 'approved'])->save();
        $colleague = $this->staff('hr');
        $colleague->forceFill(['kyc_status' => 'approved', 'date_of_birth' => '1990-01-01'])->save();

        $this->actingAs($this->hr)->putJson("/api/v1/hrm/staff/{$this->hr->id}", $this->payloadFor($this->hr, ['empl_name' => 'Changed', 'change_reason' => 'Typo']))->assertForbidden();
        $this->actingAs($this->hr)->post("/api/v1/hrm/staff/{$this->hr->id}/photo", ['image' => UploadedFile::fake()->image('me.png')], ['Accept' => 'application/json'])->assertForbidden();

        $this->actingAs($this->hr)->putJson("/api/v1/hrm/staff/{$colleague->id}", $this->payloadFor($colleague, ['empl_name' => 'Corrected']))
            ->assertUnprocessable()->assertJsonValidationErrors('change_reason');
        $this->actingAs($this->hr)->putJson("/api/v1/hrm/staff/{$colleague->id}", $this->payloadFor($colleague, ['empl_name' => 'Corrected', 'change_reason' => 'Name on NIDA']))->assertOk();

        $audit = AuditLog::where('action', 'Employee.kyc_details_changed')->where('auditable_id', $colleague->id)->sole();
        $this->assertSame(['first_name' => $colleague->first_name], $audit->before);
        $this->assertSame('Corrected', $audit->after['first_name']);
        $this->assertSame('Name on NIDA', $audit->after['reason']);
        $this->assertSame($this->hr->id, $audit->employee_id);
    }

    public function test_hr_uploads_pdf_documents_kept_privately_and_only_hr_opens_them(): void
    {
        Storage::fake('local');
        $employee = $this->staff('loan_officer');

        $this->actingAs($this->hr)->post("/api/v1/hrm/staff/{$employee->id}/documents", ['type' => 'cv', 'file' => UploadedFile::fake()->image('cv.png')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        foreach (['cv', 'certificate', 'contract', 'contract'] as $type) {
            $this->actingAs($this->hr)->post("/api/v1/hrm/staff/{$employee->id}/documents", ['type' => $type, 'file' => UploadedFile::fake()->create("{$type}.pdf", 200, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        }
        $this->assertSame(4, EmployeeDocument::where('employee_id', $employee->id)->count(), 'A new contract keeps the earlier one.');

        $document = EmployeeDocument::where('type', 'cv')->sole();
        Storage::disk('local')->assertExists($document->file_path);
        $this->actingAs($this->hr)->get("/api/v1/hrm/staff/{$employee->id}/documents/{$document->id}")->assertOk();
        $this->actingAs($employee)->getJson("/api/v1/hrm/staff/{$employee->id}/documents/{$document->id}")->assertForbidden();
        $this->actingAs($employee)->getJson("/api/v1/hrm/staff/{$employee->id}/documents")->assertForbidden();
        $this->actingAs($this->hr)->getJson("/api/v1/hrm/staff/{$employee->id}/documents")->assertOk()->assertJsonCount(4, 'data');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'empl_name' => 'Neema', 'emp_mname' => 'Ali', 'emp_lname' => 'Juma', 'empl_no' => '0778296279',
            'date_birth' => '1995-01-01', 'empl_email' => 'neema@example.com', 'blanch_id' => $this->admin->branch_id,
            'position_id' => 'employee', 'username' => 'neema', 'empl_sex' => 'Female',
            'role_id' => $this->admin->company->roles()->where('key', 'loan_officer')->value('id'),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payloadFor(Employee $employee, array $overrides): array
    {
        return array_merge([
            'empl_name' => $employee->first_name, 'emp_mname' => $employee->middle_name ?: 'M', 'emp_lname' => $employee->last_name, 'empl_no' => $employee->phone,
            'date_birth' => $employee->date_of_birth?->toDateString() ?? '1990-01-01', 'empl_email' => $employee->email, 'blanch_id' => $employee->branch_id,
            'position_id' => $employee->position, 'username' => $employee->username, 'empl_sex' => $employee->gender ?: 'male',
            'role_id' => $employee->role_id,
        ], $overrides);
    }

    private function staff(string $role): Employee
    {
        return Employee::factory()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->admin->branch_id,
            'role_id' => $this->admin->company->roles()->where('key', $role)->value('id'),
        ]);
    }
}

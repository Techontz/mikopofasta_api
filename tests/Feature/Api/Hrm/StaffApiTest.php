<?php

namespace Tests\Feature\Api\Hrm;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\LedgerAccount;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StaffApiTest extends TestCase
{
    use RefreshDatabase;

    private function roleId(Employee $admin, string $key): int
    {
        return (int) $admin->company->roles()->where('key', $key)->value('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Employee $admin, array $overrides = []): array
    {
        return array_merge([
            'empl_name' => 'Jj', 'emp_mname' => 'Uu', 'emp_lname' => 'Yy', 'empl_no' => '0778296279',
            'date_birth' => '1995-01-01', 'empl_email' => 'jj@example.com', 'blanch_id' => $admin->branch_id,
            'position_id' => 'employee', 'username' => 'Ufff', 'empl_sex' => 'Male',
            'role_id' => $this->roleId($admin, 'loan_officer'),
        ], $overrides);
    }

    public function test_lists_active_and_rejected_staff(): void
    {
        $admin = $this->signInAdmin();
        $staff = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id]);
        $rejected = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'status' => 'rejected']);

        $this->getJson('/api/v1/hrm/staff')->assertOk()->assertJsonFragment(['phone' => $staff->phone])->assertJsonMissing(['phone' => $rejected->phone]);
        $this->getJson('/api/v1/hrm/staff?status=rejected')->assertOk()->assertJsonFragment(['phone' => $rejected->phone])->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/hrm/branches')->assertOk()->assertJsonPath('data.0.name', $admin->branch->name);
    }

    public function test_registers_employee_with_role_salary_and_staff_ledger_accounts(): void
    {
        $admin = $this->signInAdmin();

        $response = $this->postJson('/api/v1/hrm/staff', $this->payload($admin, [
            'password' => 'secret123', 'salary' => '300,000', 'account_name' => 'NMB', 'account_number' => '877',
        ]))->assertCreated()->assertJsonPath('message', 'Employee Registered successfully');

        $employee = Employee::findOrFail($response->json('data.id'));
        $this->assertMatchesRegularExpression('/^MK-\d{3}\d{4}$/', $employee->employee_number);
        $this->assertTrue(Hash::check('secret123', $employee->password));
        $this->assertSame('loan_officer', $employee->role->key);
        $this->assertEquals(300000, $employee->salaryInfo->salary);
        $this->assertSame('branch', $employee->salaryInfo->salary_type);
        $this->assertSame(4, LedgerAccount::where('employee_id', $employee->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'Employee.registered', 'auditable_id' => $employee->id]);
    }

    public function test_default_password_is_phone_and_zone_manager_requires_zone(): void
    {
        $admin = $this->signInAdmin();

        $this->postJson('/api/v1/hrm/staff', $this->payload($admin, ['role_id' => $this->roleId($admin, 'zone_manager')]))
            ->assertUnprocessable()->assertJsonValidationErrors('zone_id');
        $this->postJson('/api/v1/hrm/staff', $this->payload($admin, ['empl_no' => '', 'role_id' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors(['empl_no', 'role_id']);

        $zone = Zone::create(['company_id' => $admin->company_id, 'name' => 'Lake']);
        $this->postJson('/api/v1/hrm/staff', $this->payload($admin, ['role_id' => $this->roleId($admin, 'zone_manager'), 'zone_id' => $zone->id]))->assertCreated();

        $employee = Employee::where('phone', '0778296279')->firstOrFail();
        $this->assertTrue(Hash::check('0778296279', $employee->password));
        $this->assertSame($zone->id, $employee->zone_id);
    }

    public function test_update_salary_block_reject_reset_and_delete(): void
    {
        $admin = $this->signInAdmin();
        $staff = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'phone' => '0799']);

        $this->putJson("/api/v1/hrm/staff/{$staff->id}", $this->payload($admin, ['empl_no' => '0711', 'emp_lname' => 'mpya', 'role_id' => $this->roleId($admin, 'teller')]))
            ->assertOk()->assertJsonPath('message', 'Employee Updated successfully');
        $this->assertSame('mpya', $staff->fresh()->last_name);

        $this->putJson("/api/v1/hrm/staff/{$staff->id}/salary", ['salary' => '100000', 'account_name' => 'CRDB', 'account_number' => '898657465', 'fee_salary' => '0', 'salary_type' => 'hq', 'commission_eligible' => true, 'payment_method' => 'mobile'])
            ->assertOk()->assertJsonPath('message', 'Salary Information Saved successfully');
        $this->assertFalse($staff->fresh()->salaryInfo->commission_eligible);
        $this->getJson("/api/v1/hrm/staff/{$staff->id}")->assertOk()->assertJsonPath('data.salary_info.account_number', '898657465');

        $this->postJson("/api/v1/hrm/staff/{$staff->id}/block")->assertOk();
        $this->assertSame('blocked', $staff->fresh()->status);
        $this->postJson("/api/v1/hrm/staff/{$staff->id}/block");
        $this->assertSame('active', $staff->fresh()->status);

        config(['hrm.default_staff_password' => 'Test-Default#Staff9!']);
        $this->postJson("/api/v1/hrm/staff/{$staff->id}/reset-password")->assertOk();
        $this->assertTrue(Hash::check('Test-Default#Staff9!', $staff->fresh()->password));

        $this->postJson("/api/v1/hrm/staff/{$staff->id}/reject")->assertOk();
        $this->assertSame('rejected', $staff->fresh()->status);

        $this->deleteJson("/api/v1/hrm/staff/{$staff->id}")->assertOk();
        $this->assertModelMissing($staff);

        $this->postJson("/api/v1/hrm/staff/{$admin->id}/block")->assertUnprocessable();
    }

    public function test_block_all_keeps_signed_in_admin_active(): void
    {
        $admin = $this->signInAdmin();
        $staff = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id]);

        $this->postJson('/api/v1/hrm/staff/block-all', ['block' => true])->assertOk();
        $this->assertSame('blocked', $staff->fresh()->status);
        $this->assertSame('active', $admin->fresh()->status);

        $this->postJson('/api/v1/hrm/staff/block-all', ['block' => false])->assertOk();
        $this->assertSame('active', $staff->fresh()->status);
    }

    public function test_password_change_and_photo_upload(): void
    {
        Storage::fake('public');
        $admin = $this->signInAdmin();
        $staff = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'password' => 'secret1']);

        $this->putJson("/api/v1/hrm/staff/{$staff->id}/password", ['oldpass' => 'wrong', 'newpass' => 'newpass', 'passconf' => 'newpass'])->assertUnprocessable();
        $this->putJson("/api/v1/hrm/staff/{$staff->id}/password", ['oldpass' => 'secret1', 'newpass' => 'newpass', 'passconf' => 'newpass'])->assertOk();
        $this->assertTrue(Hash::check('newpass', $staff->fresh()->password));

        $this->post("/api/v1/hrm/staff/{$staff->id}/photo", ['image' => UploadedFile::fake()->image('pass.png')], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertExists($staff->fresh()->photo);
    }

    public function test_permission_and_branch_scope(): void
    {
        $admin = $this->signInAdmin();
        $otherBranch = Branch::factory()->create(['company_id' => $admin->company_id]);
        $foreign = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $otherBranch->id]);
        $otherCompany = Employee::factory()->create();

        $this->getJson("/api/v1/hrm/staff/{$otherCompany->id}")->assertNotFound();

        $teller = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'role_id' => $this->roleId($admin, 'teller')]);
        $this->actingAs($teller);
        $this->getJson('/api/v1/hrm/staff')->assertForbidden();

        $manager = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'role_id' => $this->roleId($admin, 'hr')]);
        $manager->role->update(['key' => 'branch_hr']);
        $this->actingAs($manager->fresh());
        $this->getJson("/api/v1/hrm/staff/{$foreign->id}")->assertNotFound();
        $this->getJson('/api/v1/hrm/staff')->assertOk()->assertJsonMissing(['phone' => $foreign->phone]);
    }
}

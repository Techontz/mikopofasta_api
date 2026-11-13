<?php

namespace Tests\Feature\Api\Loans;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeePermission;
use App\Models\PhoneBondDevice;
use App\Models\PhoneBondLockRequest;
use App\Services\PhoneBondLocks;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Payments\InteractsWithRepayments;
use Tests\TestCase;

/**
 * eSIMU → Company Devices: company smartphones registered and enrolled with Linda under the company, locked and unlocked
 * only on the phone's confirmation, lost / stolen reports, audit, the two per-user privileges and branch scope. They never
 * appear among eSIMU loan devices.
 */
class CompanyDeviceTest extends TestCase
{
    use InteractsWithRepayments, RefreshDatabase;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['integrations.linda.driver' => 'test']);
        $this->admin = $this->signInAdmin();
        $this->admin->company->forceFill(['phone' => '0754000999'])->save();
    }

    public function test_a_company_device_is_registered_enrolled_and_kept_apart_from_loan_devices(): void
    {
        $this->postJson('/api/v1/company-devices', $this->device(['imei' => '12345']))->assertUnprocessable()->assertJsonValidationErrors('imei');
        $this->postJson('/api/v1/company-devices', $this->device(['department' => '']))->assertUnprocessable()->assertJsonValidationErrors('department');

        $device = $this->postJson('/api/v1/company-devices', $this->device())->assertCreated()
            ->assertJsonPath('data.enrollment_status', 'pending')->assertJsonPath('data.department', 'Finance')->json('data');
        $this->assertStringContainsString('<svg', (string) $device['qr_svg']);

        $this->postJson("/api/v1/company-devices/{$device['id']}/enrollment")->assertOk()->assertJsonPath('data.enrollment_status', 'enrolled')->assertJsonPath('data.status', 'active');

        $this->assertSame([$device['id']], array_column($this->getJson('/api/v1/company-devices')->json('data'), 'id'));
        $this->assertSame([], $this->getJson('/api/v1/phone-bond/devices?status=all')->json('data'), 'Company devices are not eSIMU loan devices.');
        $this->getJson("/api/v1/phone-bond/devices/{$device['id']}")->assertNotFound();
        $this->assertSame(0, $this->getJson('/api/v1/phone-bond/report')->json('data.cards.active_devices'));
        $this->assertNull(PhoneBondDevice::findOrFail($device['id'])->customer_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'PhoneBond.enrolled', 'auditable_id' => $device['id']]);
    }

    public function test_linda_unavailable_keeps_the_device_for_a_safe_retry(): void
    {
        config(['integrations.linda.test_outage' => true]);
        $id = $this->postJson('/api/v1/company-devices', $this->device())->assertCreated()->assertJsonPath('data.enrollment_status', 'not_registered')->json('data.id');

        config(['integrations.linda.test_outage' => false]);
        $this->postJson('/api/v1/company-devices', $this->device())->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson("/api/v1/company-devices/{$id}/enrollment")->assertOk()->assertJsonPath('data.enrollment_status', 'enrolled');
        $this->assertSame(1, PhoneBondDevice::count());
    }

    public function test_lock_and_unlock_follow_the_phones_confirmation_and_failures_are_kept(): void
    {
        $id = $this->enrolled('351234567890401');

        $this->postJson("/api/v1/company-devices/{$id}/lock", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/company-devices/{$id}/lock", ['reason' => 'Staff left the company'])->assertOk();
        $this->assertSame(PhoneBondDevice::LOCK_PENDING, PhoneBondDevice::find($id)->lock_state);

        $this->travel(2)->minutes();
        app(PhoneBondLocks::class)->sync(CarbonImmutable::now());
        $this->assertSame(PhoneBondDevice::LOCKED, PhoneBondDevice::find($id)->lock_state);

        $failing = $this->enrolled('351234567890406');
        $this->postJson("/api/v1/company-devices/{$failing}/lock", ['reason' => 'Lost in transit'])->assertOk();
        $this->travel(2)->minutes();
        app(PhoneBondLocks::class)->sync(CarbonImmutable::now());
        $this->assertSame([PhoneBondDevice::UNLOCKED, 'failed'], [PhoneBondDevice::find($failing)->lock_state, PhoneBondLockRequest::where('device_id', $failing)->value('status')]);

        $audit = $this->getJson("/api/v1/company-devices/{$id}")->assertOk()->json('data.audit');
        $this->assertContains('PhoneBond.locked', array_column($audit, 'action'));
    }

    public function test_lost_or_stolen_is_recorded_without_removing_the_device(): void
    {
        $id = $this->enrolled('351234567890411');

        $this->postJson("/api/v1/company-devices/{$id}/incidents", ['type' => 'stolen', 'details' => 'Stolen from the branch office'])->assertCreated();

        $detail = $this->getJson("/api/v1/company-devices/{$id}")->assertOk()->json('data');
        $this->assertSame(['stolen', 'active'], [$detail['condition'], $detail['status']]);
        $this->assertSame('Stolen from the branch office', $detail['incidents'][0]['details']);
        $this->assertContains('PhoneBond.incident_reported', array_column($detail['audit'], 'action'));
    }

    public function test_each_privilege_is_assigned_separately_and_enforced_on_the_api(): void
    {
        $id = $this->enrolled('351234567890421');
        $registrar = $this->staffWith(['register_company_device']);
        $locker = $this->staffWith(['manage_company_device_lock_unlock']);
        $eSimuStaff = $this->employeeWithRole($this->admin, 'credit_officer');

        $this->actingAs($eSimuStaff)->getJson('/api/v1/company-devices')->assertForbidden();
        $this->actingAs($eSimuStaff)->postJson("/api/v1/phone-bond/devices/{$id}/lock", ['reason' => 'Try'])->assertNotFound();

        $this->actingAs($registrar)->postJson('/api/v1/company-devices', $this->device(['imei' => '351234567890431']))->assertCreated();
        $this->actingAs($registrar)->postJson("/api/v1/company-devices/{$id}/lock", ['reason' => 'Try'])->assertForbidden();

        $this->actingAs($locker)->postJson('/api/v1/company-devices', $this->device(['imei' => '351234567890441']))->assertForbidden();
        $this->actingAs($locker)->postJson("/api/v1/company-devices/{$id}/lock", ['reason' => 'Device misuse'])->assertOk();
    }

    public function test_branch_staff_only_see_company_devices_of_their_branch(): void
    {
        $other = Branch::factory()->create(['company_id' => $this->admin->company_id]);
        $this->enrolled('351234567890451', $other->id);
        $own = $this->enrolled('351234567890461');
        $branchStaff = $this->staffWith(['register_company_device'], 'loan_officer');

        $this->assertSame([$own], array_column($this->actingAs($branchStaff)->getJson('/api/v1/company-devices')->json('data'), 'id'));
        $this->actingAs($branchStaff)->postJson('/api/v1/company-devices', $this->device(['imei' => '351234567890471', 'branch_id' => $other->id]))->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function device(array $overrides = []): array
    {
        return $overrides + ['imei' => '351234567890391', 'make' => 'Samsung', 'model' => 'Galaxy A15', 'department' => 'Finance', 'branch_id' => $this->admin->branch_id];
    }

    private function enrolled(string $imei, ?int $branchId = null): int
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/company-devices', $this->device(['imei' => $imei, 'branch_id' => $branchId ?? $this->admin->branch_id]))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/company-devices/{$id}/enrollment")->assertOk()->assertJsonPath('data.enrollment_status', 'enrolled');

        return (int) $id;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffWith(array $permissions, string $role = 'finance'): Employee
    {
        $employee = $this->employeeWithRole($this->admin, $role);
        foreach ($permissions as $permission) {
            EmployeePermission::create(['employee_id' => $employee->id, 'permission' => $permission, 'granted' => true]);
        }

        return $employee->fresh();
    }
}

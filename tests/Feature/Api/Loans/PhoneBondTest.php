<?php

namespace Tests\Feature\Api\Loans;

use App\Enums\LoanStatus;
use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanCategory;
use App\Models\PhoneBondDevice;
use App\Models\PhoneBondLockRequest;
use App\Services\PhoneBond;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Payments\InteractsWithRepayments;
use Tests\TestCase;

/**
 * Enhancement §5: Phone Bond devices, eligibility, lock / unlock only on Linda's confirmation, reports.
 */
class PhoneBondTest extends TestCase
{
    use InteractsWithRepayments, RefreshDatabase;

    private Employee $admin;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();

        config(['integrations.linda.driver' => 'test']);
        $this->admin = $this->signInAdmin();
        $this->loan = $this->phoneBondLoan('WAJASIRIAMALI');
    }

    public function test_public_servants_and_retired_public_servants_cannot_take_phone_bond(): void
    {
        foreach (['WATUMISHI_WA_UMMA', 'MSTAAFU_UMMA'] as $code) {
            $loan = $this->phoneBondLoan($code);
            $this->postJson('/api/v1/phone-bond/devices', ['loan_id' => $loan->id, 'imei' => '35'.random_int(100000000000, 999999999999)])->assertUnprocessable()->assertJsonValidationErrors('category_id');
        }
        $this->assertSame(0, PhoneBondDevice::count());
    }

    public function test_an_unpaid_instalment_after_the_grace_day_locks_and_payment_unlocks_on_lindas_confirmation(): void
    {
        $device = $this->device('351234567890121');
        $this->loan->schedules()->update(['due_date' => today()->subDay()->toDateString()]);

        app(PhoneBond::class)->enforce(CarbonImmutable::today());
        $this->assertSame(PhoneBondDevice::UNLOCKED, $device->fresh()->lock_state, 'One grace day after the due date.');

        $this->loan->schedules()->update(['due_date' => today()->subDays(2)->toDateString()]);
        app(PhoneBond::class)->enforce(CarbonImmutable::today());
        $this->assertSame(PhoneBondDevice::LOCKED, $device->fresh()->lock_state);
        $this->assertSame(['action' => 'lock', 'trigger' => 'auto', 'status' => 'confirmed'], PhoneBondLockRequest::sole()->only(['action', 'trigger', 'status']));

        $this->loan->schedules()->update(['paid_amount' => 130000]);
        app(PhoneBond::class)->enforce(CarbonImmutable::today());
        $this->assertSame(PhoneBondDevice::UNLOCKED, $device->fresh()->lock_state);
    }

    public function test_nothing_is_reported_locked_before_linda_confirms(): void
    {
        $accepted = $this->device('351234567890120');
        $this->postJson("/api/v1/phone-bond/devices/{$accepted->id}/lock", ['reason' => 'Customer unreachable'])->assertOk();
        $this->assertSame(PhoneBondDevice::LOCK_PENDING, $accepted->fresh()->lock_state);
        $this->postJson("/api/v1/phone-bond/devices/{$accepted->id}/unlock", ['reason' => 'x'])->assertUnprocessable();

        $request = PhoneBondLockRequest::sole();
        $this->postJson("/api/v1/phone-bond/requests/{$request->id}/confirm", ['reference' => 'LINDA-77'])->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertSame(PhoneBondDevice::LOCKED, $accepted->fresh()->lock_state);
    }

    public function test_an_unconfigured_linda_never_reports_a_lock(): void
    {
        config(['integrations.linda.driver' => 'none']);
        $device = $this->device('351234567890131', $this->phoneBondLoan('SEKTA_BINAFSI'));

        $this->postJson("/api/v1/phone-bond/devices/{$device->id}/lock", ['reason' => 'Arrears'])->assertUnprocessable()->assertJsonPath('data.status', 'failed');
        $this->assertSame(PhoneBondDevice::UNLOCKED, $device->fresh()->lock_state);
    }

    public function test_manual_lock_needs_permission_and_a_reason(): void
    {
        $device = $this->device('351234567890141');
        $officer = $this->employeeWithRole($this->admin, 'loan_officer');

        $this->actingAs($officer)->postJson("/api/v1/phone-bond/devices/{$device->id}/lock", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->admin)->postJson("/api/v1/phone-bond/devices/{$device->id}/lock", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_a_repaid_loan_makes_the_device_done_and_closing_needs_a_reason(): void
    {
        $device = $this->device('351234567890151');
        $this->postJson("/api/v1/phone-bond/devices/{$device->id}/close", ['reason' => 'Damaged'])->assertUnprocessable();

        $this->loan->forceFill(['status' => LoanStatus::Closed])->save();
        app(PhoneBond::class)->enforce(CarbonImmutable::today());
        $this->assertSame(PhoneBondDevice::DONE, $device->fresh()->status);

        $this->postJson("/api/v1/phone-bond/devices/{$device->id}/close", ['reason' => 'Service no longer required'])->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_a_top_up_keeps_the_device_and_sends_the_new_debt(): void
    {
        $device = $this->device('351234567890161');
        $topUp = $this->phoneBondLoan('WAJASIRIAMALI');

        $request = app(PhoneBond::class)->moveToTopUp($this->loan, $topUp, $this->admin);

        $this->assertSame([$topUp->id, PhoneBondDevice::ACTIVE, 'enrolled'], [(int) $device->fresh()->loan_id, $device->fresh()->status, $device->fresh()->enrollment_status]);
        $this->assertSame(['debt_update', 'confirmed'], [$request->action, $request->status]);
        $this->assertEquals(130000, $request->amount);
    }

    public function test_the_report_counts_devices_and_lists_lock_history(): void
    {
        $device = $this->device('351234567890171');
        $this->postJson("/api/v1/phone-bond/devices/{$device->id}/lock", ['reason' => 'Arrears'])->assertOk();

        $data = $this->getJson('/api/v1/phone-bond/report')->assertOk()->json('data');

        $this->assertSame([1, 1, 0, 0, 1], [$data['cards']['total_devices'], $data['cards']['active_devices'], $data['cards']['done_devices'], $data['cards']['closed_devices'], $data['cards']['locked_devices']]);
        $this->assertSame(1, $data['cards']['outstanding_loans']);
        $this->assertCount(1, $data['history']);
    }

    private function phoneBondLoan(string $customerType): Loan
    {
        $type = CustomerCategory::where('company_id', $this->admin->company_id)->where('code', $customerType)->first()
            ?? tap(CustomerCategory::factory()->create(['company_id' => $this->admin->company_id]), fn (CustomerCategory $created) => $created->forceFill(['code' => $customerType])->saveQuietly());
        $loan = $this->activeLoan($this->admin, customer: ['customer_category_id' => $type->id]);
        $category = LoanCategory::factory()->forCustomerType($type)->create(['loan_method' => 'phone_bond']);
        $loan->forceFill(['loan_category_id' => $category->id])->save();

        return $loan->fresh();
    }

    private function device(string $imei, ?Loan $loan = null): PhoneBondDevice
    {
        $id = $this->postJson('/api/v1/phone-bond/devices', ['loan_id' => ($loan ?? $this->loan)->id, 'imei' => $imei, 'linda_device_id' => 'LD-'.$imei])->assertCreated()->json('data.id');

        return PhoneBondDevice::findOrFail($id);
    }
}

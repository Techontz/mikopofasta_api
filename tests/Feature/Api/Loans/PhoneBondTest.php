<?php

namespace Tests\Feature\Api\Loans;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Models\LindaWebhookEvent;
use App\Models\Loan;
use App\Models\LoanCategory;
use App\Models\Payment;
use App\Models\PhoneBondDevice;
use App\Models\PhoneBondDeviceLoan;
use App\Models\PhoneBondLockRequest;
use App\Models\SmsLog;
use App\Services\Ledger;
use App\Services\LoanAgreement;
use App\Services\LoanService;
use App\Services\PhoneBond;
use App\Services\PhoneBondLocks;
use App\Services\Sms\SmsSender;
use App\Services\Sms\SmsTemplates;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UsesSecondApprover;
use Tests\Feature\Api\Payments\InteractsWithRepayments;
use Tests\TestCase;

/**
 * eSIMU (the Phone Bond loan method) with Linda Device Lock: registration and QR enrollment before submission, drafts while
 * Linda is down, device reuse, the morning lock and payment unlock on the phone's confirmation, webhooks, release,
 * replacement, lost / stolen reports, the Payments view, permissions and audit.
 */
class PhoneBondTest extends TestCase
{
    use InteractsWithRepayments, RefreshDatabase, UsesSecondApprover;

    private const SECRET = 'whsec_test';

    private Employee $admin;

    private Customer $customer;

    private LoanCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        config(['integrations.linda.driver' => 'test', 'integrations.linda.webhook_secret' => self::SECRET, 'integrations.linda.grace_days' => 0]);
        $this->admin = $this->signInAdmin();
        $this->grantSelfApproval($this->admin);
        $customerType = CustomerCategory::factory()->create(['company_id' => $this->admin->company_id]);
        $this->customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'phone' => '255754000123', 'customer_category_id' => $customerType->id]);
        $this->category = LoanCategory::factory()->forCustomerType($customerType)->create(['insurance' => 0]);
        $this->category->branches()->attach($this->admin->branch_id);
        app(Ledger::class)->openingBalance($this->admin->company_id, Account::Principal, 1000000, 'FLOAT');
    }

    public function test_an_application_is_submitted_only_after_linda_confirms_enrollment(): void
    {
        $this->postJson('/api/v1/phone-bond/registrations', $this->phone('35123456789012'))->assertUnprocessable()->assertJsonValidationErrors('imei');

        $neverScanned = $this->postJson('/api/v1/phone-bond/registrations', $this->phone('351234567890129'))->assertCreated()
            ->assertJsonPath('data.enrollment_status', 'pending')->assertJsonPath('data.status', 'pending')->json('data');
        $this->assertStringContainsString('<svg', (string) $neverScanned['qr_svg'], 'The enrollment QR is shown.');

        $this->postJson("/api/v1/phone-bond/devices/{$neverScanned['id']}/enrollment")->assertOk()->assertJsonPath('data.enrollment_status', 'pending');
        $this->postJson(route('api.v1.loans.store'), $this->form(['loan_method' => 'phone_bond']))->assertUnprocessable()->assertJsonValidationErrors('device_id');
        $this->postJson(route('api.v1.loans.store'), $this->form(['loan_method' => 'phone_bond', 'device_id' => $neverScanned['id']]))->assertUnprocessable()->assertJsonValidationErrors('device_id');

        $device = $this->enrolledDevice('351234567890121');
        $this->postJson(route('api.v1.loans.store'), $this->form(['loan_method' => 'phone_bond', 'device_id' => $device->id]))->assertCreated();

        $loan = Loan::latest('id')->firstOrFail();
        $this->assertSame([PhoneBondDevice::ACTIVE, $loan->id, 'ld-351234567890121'], [$device->fresh()->status, (int) $device->fresh()->loan_id, $device->fresh()->linda_device_id]);
        $this->assertSame(LoanStatus::PendingManagerApproval, $loan->status, 'eSIMU uses the existing approval workflow.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'PhoneBond.enrolled', 'auditable_id' => $device->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'PhoneBond.linked_to_loan', 'auditable_id' => $device->id]);
    }

    public function test_linda_unavailable_keeps_a_pending_registration_that_retries_without_duplicates(): void
    {
        config(['integrations.linda.test_outage' => true]);
        $draft = $this->postJson('/api/v1/phone-bond/registrations', $this->phone('351234567890131') + ['application' => ['how_loan' => 150000, 'session' => 4]])
            ->assertCreated()->assertJsonPath('data.enrollment_status', 'not_registered')->json('data');
        $this->assertSame(150000, $draft['application']['how_loan'], 'The application form is kept with the draft.');
        $this->postJson(route('api.v1.loans.store'), $this->form(['loan_method' => 'phone_bond', 'device_id' => $draft['id']]))->assertUnprocessable();
        $this->assertSame(0, Loan::count(), 'A pending registration never enters the approval workflow.');

        config(['integrations.linda.test_outage' => false]);
        $this->postJson('/api/v1/phone-bond/registrations', $this->phone('351234567890131'))->assertCreated()->assertJsonPath('data.id', $draft['id']);
        $this->postJson("/api/v1/phone-bond/devices/{$draft['id']}/register")->assertOk();
        $this->postJson("/api/v1/phone-bond/devices/{$draft['id']}/enrollment")->assertOk()->assertJsonPath('data.enrollment_status', 'enrolled');

        $this->assertSame(1, PhoneBondDevice::count());
        $this->postJson(route('api.v1.loans.store'), $this->form(['loan_method' => 'phone_bond', 'device_id' => $draft['id']]))->assertCreated();
    }

    public function test_an_imei_of_another_customer_is_refused(): void
    {
        $this->enrolledDevice('351234567890141');
        $other = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id]);

        $this->postJson('/api/v1/phone-bond/registrations', ['customer_id' => $other->id] + $this->phone('351234567890141'))->assertUnprocessable()->assertJsonValidationErrors('imei');
    }

    public function test_a_settled_device_secures_a_new_loan_after_inspection_without_a_new_qr(): void
    {
        [$loan, $device] = $this->esimuLoan('351234567890151');
        $loan->forceFill(['status' => LoanStatus::Closed])->save();
        app(PhoneBond::class)->enforce(CarbonImmutable::today());
        $this->assertSame([PhoneBondDevice::DONE, 'enrolled'], [$device->fresh()->status, $device->fresh()->enrollment_status], 'A settled device stays enrolled.');

        $reusable = $this->getJson("/api/v1/phone-bond/customers/{$this->customer->id}/devices")->assertOk()->json('data.reusable');
        $this->assertSame([$device->id], array_column($reusable, 'id'));

        $this->postJson(route('api.v1.loans.store'), $this->form(['loan_method' => 'phone_bond', 'device_id' => $device->id]))->assertUnprocessable()->assertJsonValidationErrors('device_id');
        $this->postJson("/api/v1/phone-bond/devices/{$device->id}/inspection", ['condition' => 'good'])->assertOk();
        $this->postJson(route('api.v1.loans.store'), $this->form(['loan_method' => 'phone_bond', 'device_id' => $device->id, 'device_inspected' => true]))->assertCreated();

        $this->assertSame(2, PhoneBondDeviceLoan::where('device_id', $device->id)->count());
        $this->assertSame(PhoneBondDevice::ACTIVE, $device->fresh()->status);
    }

    public function test_a_damaged_device_cannot_secure_a_loan_and_its_record_is_kept(): void
    {
        [$loan, $device] = $this->esimuLoan('351234567890161');
        $loan->forceFill(['status' => LoanStatus::Closed])->save();
        $this->postJson("/api/v1/phone-bond/devices/{$device->id}/inspection", ['condition' => 'damaged', 'note' => 'Cracked screen'])->assertOk();

        $this->postJson(route('api.v1.loans.store'), $this->form(['loan_method' => 'phone_bond', 'device_id' => $device->id, 'device_inspected' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('device_id');
        $this->assertSame('damaged', $device->fresh()->condition);
        $this->assertSame([], $this->getJson("/api/v1/phone-bond/customers/{$this->customer->id}/devices")->json('data.reusable'));
    }

    public function test_an_instalment_unpaid_the_day_after_its_due_date_locks_on_the_phones_confirmation_even_if_partly_paid(): void
    {
        [$loan, $device] = $this->esimuLoan('351234567890171');
        $loan->schedules()->update(['due_date' => today()->toDateString(), 'paid_amount' => 30000]);

        app(PhoneBond::class)->enforce(CarbonImmutable::today());
        $this->assertSame(0, PhoneBondLockRequest::count(), 'Nothing locks on the due date itself.');

        $loan->schedules()->update(['due_date' => today()->subDay()->toDateString()]);
        app(PhoneBond::class)->enforce(CarbonImmutable::today());
        $this->assertSame(PhoneBondDevice::LOCK_PENDING, $device->fresh()->lock_state, 'A queued command is not a lock.');
        $request = PhoneBondLockRequest::sole();
        $this->assertSame(['lock', 'auto', 'pending', 'QUEUED'], [$request->action, $request->trigger, $request->status, $request->command_status]);

        app(PhoneBond::class)->enforce(CarbonImmutable::today());
        $this->assertSame(1, PhoneBondLockRequest::count(), 'Repeated monitoring never stacks commands.');

        $this->travel(2)->minutes();
        app(PhoneBondLocks::class)->sync(CarbonImmutable::now());
        $this->assertSame([PhoneBondDevice::LOCKED, 'confirmed'], [$device->fresh()->lock_state, $request->fresh()->status]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'PhoneBond.locked', 'auditable_id' => $device->id]);
    }

    public function test_full_settlement_unlocks_on_lindas_webhook_and_keeps_the_device_enrolled(): void
    {
        [$loan, $device] = $this->lockedEsimuLoan('351234567890181');

        app(LoanService::class)->deposit($loan, 130000, CarbonImmutable::today());

        $this->assertSame(LoanStatus::Closed, $loan->fresh()->status);
        $this->assertSame([PhoneBondDevice::DONE, PhoneBondDevice::UNLOCK_PENDING], [$device->fresh()->status, $device->fresh()->lock_state]);
        $unlock = PhoneBondLockRequest::where('action', 'unlock')->sole();

        $this->webhook('evt-1', 'device.unlocked', ['commandId' => $unlock->command_id, 'deviceId' => $device->linda_device_id, 'commandType' => 'UNLOCK', 'result' => 'DONE', 'deviceStatus' => 'ACTIVE', 'idempotencyKey' => $unlock->idempotency_key])->assertOk();

        $this->assertSame([PhoneBondDevice::UNLOCKED, PhoneBondDevice::DONE, 'enrolled'], [$device->fresh()->lock_state, $device->fresh()->status, $device->fresh()->enrollment_status]);
        $this->assertSame('confirmed', $unlock->fresh()->status);
    }

    public function test_a_payment_that_clears_the_arrears_unlocks_while_the_loan_runs(): void
    {
        [$loan, $device] = $this->lockedEsimuLoan('351234567890191');
        $loan->schedules()->delete();
        $loan->schedules()->create(['due_date' => today()->subDay(), 'amount' => 65000]);
        $loan->schedules()->create(['due_date' => today()->addDays(7), 'amount' => 65000]);

        app(LoanService::class)->deposit($loan, 30000, CarbonImmutable::today());
        $this->assertSame(PhoneBondDevice::LOCKED, $device->fresh()->lock_state, 'A partial payment does not unlock.');

        app(LoanService::class)->deposit($loan, 35000, CarbonImmutable::today());
        $this->assertSame(PhoneBondDevice::UNLOCK_PENDING, $device->fresh()->lock_state);
        $this->assertSame(PhoneBondDevice::ACTIVE, $device->fresh()->status);
    }

    public function test_webhooks_are_signed_applied_once_and_a_failure_alerts_staff(): void
    {
        [, $device] = $this->esimuLoan('351234567890100');
        $creditOfficer = $this->employeeWithRole($this->admin, 'credit_officer');
        $loanOfficer = $this->employeeWithRole($this->admin, 'loan_officer');
        $this->postJson("/api/v1/phone-bond/devices/{$device->id}/lock", ['reason' => 'Customer unreachable'])->assertOk();
        $request = PhoneBondLockRequest::sole();
        $this->assertSame(PhoneBondDevice::LOCK_PENDING, $device->fresh()->lock_state);

        $this->postJson('/api/webhooks/linda', ['id' => 'evt-x', 'type' => 'device.locked', 'data' => []], ['X-DeviceLock-Signature' => 't='.time().',v1=bad'])->assertStatus(400);

        $data = ['commandId' => $request->command_id, 'deviceId' => $device->linda_device_id, 'commandType' => 'LOCK', 'result' => 'FAILED', 'deviceStatus' => null, 'detail' => 'Agent missing'];
        $this->webhook('evt-2', 'command.failed', $data)->assertOk();
        $this->webhook('evt-2', 'command.failed', $data)->assertOk();

        $this->assertSame(1, LindaWebhookEvent::count());
        $this->assertSame([PhoneBondDevice::UNLOCKED, 'failed'], [$device->fresh()->lock_state, $request->fresh()->status]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'PhoneBond.lock_failed')->count());
        $this->assertSame(1, $creditOfficer->notifications()->count(), 'Staff who may lock are alerted once.');
        $this->assertSame('esimu_command_failed', $creditOfficer->notifications()->first()->data['kind']);
        $this->assertSame(0, $loanOfficer->notifications()->count());
    }

    public function test_an_unconfigured_linda_never_registers_or_locks(): void
    {
        config(['integrations.linda.driver' => 'none']);
        $this->postJson('/api/v1/phone-bond/registrations', $this->phone('351234567890211'))->assertCreated()->assertJsonPath('data.enrollment_status', 'not_registered');

        $device = PhoneBondDevice::create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'customer_id' => $this->customer->id, 'imei' => '351234567890201',
            'linda_device_id' => 'ld-351234567890201', 'enrollment_status' => 'enrolled', 'status' => 'active', 'lock_state' => 'unlocked']);
        $request = app(PhoneBondLocks::class)->request($device, 'lock', 'manual', 'Arrears', $this->admin);

        $this->assertSame([PhoneBondLockRequest::FAILED, PhoneBondDevice::UNLOCKED], [$request->status, $device->fresh()->lock_state]);
    }

    public function test_manual_lock_and_unlock_follow_the_roles_and_need_a_reason(): void
    {
        [, $device] = $this->esimuLoan('351234567890221');

        foreach (['loan_officer', 'finance'] as $role) {
            $this->actingAs($this->employeeWithRole($this->admin, $role))->postJson("/api/v1/phone-bond/devices/{$device->id}/lock", ['reason' => 'Arrears'])->assertForbidden();
        }
        $creditOfficer = $this->employeeWithRole($this->admin, 'credit_officer');
        $this->actingAs($creditOfficer)->postJson("/api/v1/phone-bond/devices/{$device->id}/lock", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($creditOfficer)->postJson("/api/v1/phone-bond/devices/{$device->id}/lock", ['reason' => 'Customer asked to pause'])->assertOk();
        $this->actingAs($this->employeeWithRole($this->admin, 'branch_manager'))->postJson("/api/v1/phone-bond/devices/{$device->id}/unlock", ['reason' => 'x'])->assertUnprocessable();
    }

    public function test_release_needs_a_completed_loan_and_an_authorised_role(): void
    {
        [$loan, $device] = $this->esimuLoan('351234567890231');
        $creditOfficer = $this->employeeWithRole($this->admin, 'credit_officer');
        $release = ['reason' => 'Customer no longer uses eSIMU', 'released_in_linda' => true];

        $this->actingAs($creditOfficer)->postJson("/api/v1/phone-bond/devices/{$device->id}/release", $release)->assertUnprocessable();
        $loan->forceFill(['status' => LoanStatus::Closed])->save();
        $this->actingAs($this->employeeWithRole($this->admin, 'branch_manager'))->postJson("/api/v1/phone-bond/devices/{$device->id}/release", $release)->assertForbidden();
        $this->actingAs($creditOfficer)->postJson("/api/v1/phone-bond/devices/{$device->id}/release", ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('released_in_linda');
        $this->actingAs($creditOfficer)->postJson("/api/v1/phone-bond/devices/{$device->id}/release", $release)->assertOk()->assertJsonPath('data.status', 'closed');

        $this->assertDatabaseHas('phone_bond_device_loans', ['device_id' => $device->id, 'loan_id' => $loan->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'PhoneBond.released', 'auditable_id' => $device->id]);
    }

    public function test_a_replacement_takes_effect_only_after_someone_else_approves_it_and_keeps_the_loan(): void
    {
        [$loan, $old] = $this->esimuLoan('351234567890241');
        $schedule = $loan->schedules()->get(['due_date', 'amount'])->toArray();
        $officer = $this->employeeWithRole($this->admin, 'loan_officer');
        $this->actingAs($officer)->postJson("/api/v1/phone-bond/devices/{$old->id}/incidents", ['type' => 'stolen', 'details' => 'Stolen at the market'])->assertCreated();
        $new = $this->enrolledDevice('351234567890251', $officer);

        $replacement = $this->actingAs($officer)->postJson("/api/v1/phone-bond/devices/{$old->id}/replacements", ['new_device_id' => $new->id, 'reason_type' => 'stolen', 'reason' => 'Phone stolen'])
            ->assertCreated()->json('data');
        $this->assertSame($loan->id, (int) $old->fresh()->loan_id, 'Nothing changes before approval.');
        $this->assertSame($old->id, app(PhoneBond::class)->deviceFor($loan)->id);

        $this->actingAs($officer)->postJson("/api/v1/phone-bond/replacements/{$replacement['id']}/approve")->assertForbidden();
        $this->actingAs($this->admin)->postJson("/api/v1/phone-bond/replacements/{$replacement['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertSame($new->id, app(PhoneBond::class)->deviceFor($loan)->id);
        $this->assertSame([PhoneBondDevice::DONE, 'stolen'], [$old->fresh()->status, $old->fresh()->condition]);
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
        $this->assertSame($schedule, $loan->schedules()->get(['due_date', 'amount'])->toArray());
        $this->assertSame(1, $old->incidents()->count());
    }

    public function test_the_requester_cannot_approve_their_own_replacement(): void
    {
        [, $old] = $this->esimuLoan('351234567890261');
        $new = $this->enrolledDevice('351234567890271');
        $id = $this->postJson("/api/v1/phone-bond/devices/{$old->id}/replacements", ['new_device_id' => $new->id, 'reason_type' => 'damaged', 'reason' => 'Broken'])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/phone-bond/replacements/{$id}/approve")->assertUnprocessable();
    }

    public function test_the_evening_warning_is_sent_once_for_an_instalment_due_today_and_not_fully_paid(): void
    {
        [$loan] = $this->esimuLoan('351234567890281');
        $loan->schedules()->update(['due_date' => today()->toDateString(), 'paid_amount' => 50000]);

        $phoneBond = app(PhoneBond::class);
        $this->assertSame(1, $phoneBond->warn(CarbonImmutable::today(), app(SmsTemplates::class), app(SmsSender::class)));
        $this->assertSame(0, $phoneBond->warn(CarbonImmutable::today(), app(SmsTemplates::class), app(SmsSender::class)));
        $this->assertStringContainsString('80,000', (string) SmsLog::where('reference', 'like', 'esimu_lock_warning:%')->value('message'));
    }

    public function test_payments_show_only_esimu_loans_and_the_agreement_lists_the_phone(): void
    {
        [$loan, $device] = $this->esimuLoan('351234567890291');
        $normal = $this->activeLoan($this->admin);
        foreach ([[$loan, 40000, 'MP-ESIMU'], [$normal, 25000, 'MP-NORMAL']] as [$target, $amount, $reference]) {
            $payment = Payment::create(['company_id' => $this->admin->company_id, 'branch_id' => $target->branch_id, 'customer_id' => $target->customer_id, 'loan_id' => $target->id,
                'source' => Payment::SOURCE_MANUAL, 'channel' => 'MNO', 'provider' => 'M-Pesa', 'transaction_id' => $reference, 'amount' => $amount, 'allocated_amount' => $amount,
                'status' => 'allocated', 'paid_on' => today()]);
            DB::table('payment_allocations')->insert(['payment_id' => $payment->id, 'loan_id' => $target->id, 'amount' => $amount, 'created_at' => now(), 'updated_at' => now()]);
        }

        $data = $this->getJson('/api/v1/phone-bond/payments')->assertOk()->json();
        $this->assertSame(['MP-ESIMU'], array_column($data['data'], 'reference'));
        $this->assertEquals(40000, $data['cards']['collected_all_time']);
        $this->assertSame(1, $data['cards']['outstanding_loans']);

        $collaterals = app(LoanAgreement::class)->for($loan->fresh())['collaterals'];
        $this->assertSame('IMEI '.$device->imei, end($collaterals)['location']);
    }

    public function test_a_top_up_keeps_the_device_without_a_new_enrollment(): void
    {
        [$loan, $device] = $this->esimuLoan('351234567890301');
        $topUp = $this->activeLoan($this->admin, customer: []);

        app(PhoneBond::class)->moveToTopUp($loan, $topUp, $this->admin);

        $this->assertSame($device->id, app(PhoneBond::class)->deviceFor($topUp)->id);
        $this->assertNull(app(PhoneBond::class)->deviceFor($loan));
        $this->assertSame(['enrolled', PhoneBondDevice::ACTIVE], [$device->fresh()->enrollment_status, $device->fresh()->status]);
    }

    public function test_the_devices_list_searches_by_name_phone_imei_and_loan(): void
    {
        [$loan, $device] = $this->esimuLoan('351234567890311');

        foreach ([$device->imei, $loan->loan_number, $this->customer->phone, $this->customer->first_name] as $term) {
            $this->assertSame([$device->id], array_column($this->getJson('/api/v1/phone-bond/devices?search='.urlencode($term))->assertOk()->json('data'), 'id'), $term);
        }
        $this->assertSame([], $this->getJson('/api/v1/phone-bond/devices?status=closed')->json('data'));
    }

    public function test_the_http_driver_speaks_lindas_partner_api(): void
    {
        config(['integrations.linda.driver' => 'http', 'integrations.linda.api_key' => 'dlk_live_test', 'integrations.linda.base_url' => 'https://api.linda.test/v1/partner']);
        Http::fake([
            'api.linda.test/v1/partner/registrations' => Http::response(['customer' => ['id' => 'c-1'], 'device' => ['id' => 'd-1', 'status' => 'PENDING_ENROLLMENT', 'enrollment' => ['token' => 't', 'expiresAt' => now()->addDays(7)->toIso8601String(), 'qr' => ['k' => 'v']]], 'created' => ['customer' => true, 'device' => true]], 201),
            'api.linda.test/v1/partner/devices/d-1' => Http::response(['id' => 'd-1', 'status' => 'ACTIVE', 'lastCheckInAt' => now()->toIso8601String()]),
            'api.linda.test/v1/partner/devices/d-1/lock' => Http::response(['statusCode' => 409, 'message' => 'Device is already locked'], 409),
        ]);

        $id = $this->postJson('/api/v1/phone-bond/registrations', $this->phone('351234567890321'))->assertCreated()->assertJsonPath('data.linda_device_id', 'd-1')->json('data.id');
        $this->postJson("/api/v1/phone-bond/devices/{$id}/enrollment")->assertOk()->assertJsonPath('data.enrollment_status', 'enrolled');
        $this->postJson("/api/v1/phone-bond/devices/{$id}/lock", ['reason' => 'Arrears'])->assertUnprocessable()->assertJsonPath('data.status', 'failed');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.linda.test/v1/partner/registrations'
            && $request->hasHeader('Authorization', 'Bearer dlk_live_test')
            && $request['customer']['externalId'] === 'mkopa-customer-'.$this->customer->id && $request['device']['imei'] === '351234567890321');
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/lock') && str_starts_with((string) $request['idempotencyKey'], 'mkopa-esimu-'));
    }

    /**
     * @return array<string, mixed>
     */
    private function phone(string $imei): array
    {
        return ['customer_id' => $this->customer->id, 'imei' => $imei, 'make' => 'Tecno', 'model' => 'Tecno M3'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'customer_id' => $this->customer->id, 'category_id' => $this->category->id, 'how_loan' => 100000,
            'session' => 1, 'rate' => 'SIMPLE', 'fee_status' => 'YES', 'reason' => 'BIASHARA',
        ];
    }

    private function enrolledDevice(string $imei, ?Employee $as = null): PhoneBondDevice
    {
        $as ??= $this->admin;
        $id = $this->actingAs($as)->postJson('/api/v1/phone-bond/registrations', $this->phone($imei))->assertCreated()->json('data.id');
        $this->actingAs($as)->postJson("/api/v1/phone-bond/devices/{$id}/enrollment")->assertOk()->assertJsonPath('data.enrollment_status', 'enrolled');
        $this->actingAs($this->admin);

        return PhoneBondDevice::findOrFail($id);
    }

    /**
     * An active eSIMU loan of the test customer secured by an enrolled phone.
     *
     * @return array{0: Loan, 1: PhoneBondDevice}
     */
    private function esimuLoan(string $imei): array
    {
        $device = $this->enrolledDevice($imei);
        $loan = $this->activeLoan($this->admin);
        $loan->forceFill(['customer_id' => $this->customer->id, 'loan_method' => 'phone_bond'])->save();
        app(PhoneBond::class)->link($loan, $device, 'application', $this->admin);

        return [$loan->fresh(), $device->fresh()];
    }

    /**
     * @return array{0: Loan, 1: PhoneBondDevice}
     */
    private function lockedEsimuLoan(string $imei): array
    {
        [$loan, $device] = $this->esimuLoan($imei);
        $loan->schedules()->update(['due_date' => today()->subDay()->toDateString()]);
        app(PhoneBond::class)->enforce(CarbonImmutable::today());
        $this->travel(2)->minutes();
        app(PhoneBondLocks::class)->sync(CarbonImmutable::now());
        $this->assertSame(PhoneBondDevice::LOCKED, $device->fresh()->lock_state);

        return [$loan->fresh(), $device->fresh()];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function webhook(string $id, string $type, array $data): TestResponse
    {
        $body = (string) json_encode(['id' => $id, 'type' => $type, 'createdAt' => now()->toIso8601String(), 'data' => $data]);
        $timestamp = time();

        return $this->call('POST', '/api/webhooks/linda', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_DEVICELOCK_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET),
        ], $body);
    }
}

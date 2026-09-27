<?php

namespace Tests\Feature\Api\Hrm;

use App\Events\Hrm\StaffPasswordWasReset;
use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StaffPasswordResetApiTest extends TestCase
{
    use RefreshDatabase;

    private const DEFAULT_PASSWORD = 'Test-Default#Staff9!';

    protected function setUp(): void
    {
        parent::setUp();

        config(['hrm.default_staff_password' => self::DEFAULT_PASSWORD]);
    }

    private function staff(Employee $admin, string $role = 'teller', array $attributes = []): Employee
    {
        return Employee::factory()->create($attributes + [
            'company_id' => $admin->company_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->company->roles()->where('key', $role)->value('id'),
            'password' => 'old-secret-1',
        ]);
    }

    private function login(Employee $employee, string $password): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', ['phone' => $employee->phone, 'password' => $password]);
    }

    public function test_authorized_admin_resets_to_the_configured_default_password(): void
    {
        Event::fake([StaffPasswordWasReset::class]);
        $admin = $this->signInAdmin();
        $staff = $this->staff($admin);

        $this->postJson("/api/v1/hrm/staff/{$staff->id}/reset-password")
            ->assertOk()
            ->assertExactJson(['message' => 'Password reset successfully to the configured default password.']);

        $this->assertTrue(Hash::check(self::DEFAULT_PASSWORD, $staff->fresh()->password));
        Event::assertDispatched(StaffPasswordWasReset::class, fn (StaffPasswordWasReset $event): bool => $event->employee->is($staff) && $event->actor->is($admin));
        $this->assertDatabaseHas('audit_logs', ['action' => 'Employee.password_reset', 'auditable_id' => $staff->id, 'employee_id' => $admin->id]);
    }

    public function test_system_admin_role_can_reset_but_hr_and_ordinary_staff_cannot(): void
    {
        $superAdmin = $this->signInAdmin();
        $admin = $this->staff($superAdmin, 'admin');
        $hr = $this->staff($superAdmin, 'hr');
        $teller = $this->staff($superAdmin, 'teller');
        $target = $this->staff($superAdmin, 'loan_officer');

        foreach ([$teller, $hr] as $unauthorized) {
            $this->actingAs($unauthorized);
            $this->postJson("/api/v1/hrm/staff/{$target->id}/reset-password")->assertForbidden();
            $this->assertTrue(Hash::check('old-secret-1', $target->fresh()->password));
        }

        $this->actingAs($admin);
        $this->postJson("/api/v1/hrm/staff/{$target->id}/reset-password")->assertOk();
        $this->assertTrue(Hash::check(self::DEFAULT_PASSWORD, $target->fresh()->password));
    }

    public function test_password_is_stored_hashed_never_in_plaintext(): void
    {
        $admin = $this->signInAdmin();
        $staff = $this->staff($admin);

        $this->postJson("/api/v1/hrm/staff/{$staff->id}/reset-password")->assertOk();

        $stored = Employee::whereKey($staff->id)->value('password');
        $this->assertNotSame(self::DEFAULT_PASSWORD, $stored);
        $this->assertTrue(Hash::isHashed($stored));
        $this->assertTrue(Hash::check(self::DEFAULT_PASSWORD, $stored));
        $this->assertDatabaseMissing('employees', ['id' => $staff->id, 'password' => self::DEFAULT_PASSWORD]);
    }

    public function test_default_password_logs_in_old_password_fails_and_second_reset_keeps_the_same_default(): void
    {
        $admin = $this->signInAdmin();
        $staff = $this->staff($admin);

        $this->postJson("/api/v1/hrm/staff/{$staff->id}/reset-password")->assertOk();

        $this->login($staff, self::DEFAULT_PASSWORD)->assertOk()->assertJsonStructure(['token']);
        $this->login($staff, 'old-secret-1')->assertUnprocessable()->assertJsonValidationErrors('phone');

        $staff->update(['password' => 'changed-by-staff-2']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin);
        $this->postJson("/api/v1/hrm/staff/{$staff->id}/reset-password")->assertOk();

        $this->login($staff, self::DEFAULT_PASSWORD)->assertOk();
        $this->login($staff, 'changed-by-staff-2')->assertUnprocessable();
    }

    public function test_reset_revokes_existing_tokens(): void
    {
        $admin = $this->signInAdmin();
        $staff = $this->staff($admin);
        $token = $this->login($staff, 'old-secret-1')->assertOk()->json('token');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withoutToken()->actingAs($admin);
        $this->postJson("/api/v1/hrm/staff/{$staff->id}/reset-password")->assertOk();

        $this->assertSame(0, $staff->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_response_logs_and_audit_trail_never_contain_the_password(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->message.' '.json_encode($message->context);
        });
        Log::spy();

        $admin = $this->signInAdmin();
        $staff = $this->staff($admin);

        $response = $this->postJson("/api/v1/hrm/staff/{$staff->id}/reset-password")->assertOk();
        $this->assertStringNotContainsString(self::DEFAULT_PASSWORD, $response->getContent());
        $hash = $staff->fresh()->password;
        $this->assertStringNotContainsString($hash, $response->getContent());

        foreach ($logged as $line) {
            $this->assertStringNotContainsString(self::DEFAULT_PASSWORD, $line);
        }
        Log::shouldNotHaveReceived('info', fn (...$arguments): bool => str_contains(json_encode($arguments), self::DEFAULT_PASSWORD));
        Log::shouldNotHaveReceived('debug', fn (...$arguments): bool => str_contains(json_encode($arguments), self::DEFAULT_PASSWORD));
        Log::shouldNotHaveReceived('warning', fn (...$arguments): bool => str_contains(json_encode($arguments), self::DEFAULT_PASSWORD));
        Log::shouldNotHaveReceived('error', fn (...$arguments): bool => str_contains(json_encode($arguments), self::DEFAULT_PASSWORD));

        $audits = AuditLog::query()->get()->map(fn (AuditLog $log): string => json_encode($log->getAttributes()))->implode("\n");
        $this->assertStringNotContainsString(self::DEFAULT_PASSWORD, $audits);
        $this->assertStringNotContainsString($hash, $audits);
    }

    public function test_reset_is_refused_and_changes_nothing_when_the_default_is_not_configured(): void
    {
        $admin = $this->signInAdmin();
        $staff = $this->staff($admin);
        $token = $this->login($staff, 'old-secret-1')->json('token');
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin);

        foreach ([null, ''] as $missing) {
            config(['hrm.default_staff_password' => $missing]);

            $this->postJson("/api/v1/hrm/staff/{$staff->id}/reset-password")
                ->assertStatus(503)
                ->assertJsonPath('message', 'The default staff password is not configured.');
        }

        $this->assertTrue(Hash::check('old-secret-1', $staff->fresh()->password));
        $this->assertNotNull($token);
        $this->assertSame(1, $staff->tokens()->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'Employee.password_reset']);
    }

    public function test_cannot_reset_own_password_super_admin_or_other_company_staff(): void
    {
        $superAdmin = $this->signInAdmin();
        $admin = $this->staff($superAdmin, 'admin');
        $otherSuperAdmin = $this->staff($superAdmin, 'super_admin');

        $this->postJson("/api/v1/hrm/staff/{$superAdmin->id}/reset-password")->assertForbidden();

        $this->actingAs($admin);
        $this->postJson("/api/v1/hrm/staff/{$admin->id}/reset-password")->assertForbidden();
        $this->postJson("/api/v1/hrm/staff/{$otherSuperAdmin->id}/reset-password")->assertForbidden();
        $this->assertTrue(Hash::check('old-secret-1', $otherSuperAdmin->fresh()->password));

        $this->actingAs($superAdmin);
        $this->postJson("/api/v1/hrm/staff/{$otherSuperAdmin->id}/reset-password")->assertOk();

        $foreignAdmin = $this->signInAdmin();
        $this->postJson("/api/v1/hrm/staff/{$admin->id}/reset-password")->assertNotFound();
        $this->assertTrue(Hash::check('old-secret-1', $admin->fresh()->password));
        $this->assertNotSame($foreignAdmin->company_id, $admin->company_id);
    }
}

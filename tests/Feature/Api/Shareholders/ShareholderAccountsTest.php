<?php

namespace Tests\Feature\Api\Shareholders;

use App\Models\AuditLog;
use App\Models\Capital;
use App\Models\Company;
use App\Models\Employee;
use App\Models\IdempotentRequest;
use App\Models\JournalEntry;
use App\Models\ShareHolder;
use App\Services\AccessControl;
use App\Services\Shareholders\ShareholderAccounts;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Shareholder login accounts: registering a shareholder creates (or links) its login in one transaction, the temporary
 * password is shown once and never stored, and existing shareholders get accounts through "Generate accounts" / the
 * artisan command without touching any financial record.
 */
class ShareholderAccountsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ShareHolder::DISK);
        $this->admin = $this->signInAdmin();
        Sanctum::actingAs($this->admin);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'DEVFLOW', 'middle_name' => 'S', 'last_name' => 'SHAREHOLDER', 'share_mobile' => '0768999001',
            'share_email' => 'devflow.s@example.com', 'share_sex' => 'male', 'share_dob' => '1990-05-05',
            'passport_photo' => UploadedFile::fake()->image('photo.jpg', 200, 200),
        ];
    }

    private function holder(string $name, ?string $mobile, ?int $companyId = null): ShareHolder
    {
        return ShareHolder::create(['company_id' => $companyId ?? $this->admin->company_id, 'first_name' => $name, 'last_name' => 'HOLDER', 'mobile' => (string) $mobile, 'email' => strtolower($name).'@example.com', 'date_of_birth' => '1990-01-01']);
    }

    public function test_registering_a_shareholder_creates_a_linked_portal_login_with_a_one_time_temporary_password(): void
    {
        $response = $this->post('/api/v1/capital/share-holders', $this->payload(), ['Accept' => 'application/json', 'Idempotency-Key' => 'create-holder-0001'])
            ->assertCreated()
            ->assertJsonPath('message', 'Shareholder Registered successfully')
            ->assertJsonPath('account.outcome', 'created')
            ->assertJsonPath('credentials.login', '0768999001')
            ->assertJsonPath('data.login.linked', true)
            ->assertJsonPath('data.login.must_change_password', true);

        $password = $response->json('credentials.temporary_password');
        $this->assertIsString($password);
        $this->assertGreaterThanOrEqual(12, strlen($password));

        $holder = ShareHolder::firstWhere('mobile', '0768999001');
        $account = $holder->account;
        $this->assertNotNull($account);
        $this->assertSame(Employee::ACCOUNT_SHAREHOLDER, $account->account_type);
        $this->assertSame('shareholder', $account->role->key);
        $this->assertTrue($account->must_change_password);
        $this->assertNull($account->branch_id);
        $this->assertSame('active', $account->status);
        $this->assertSame((int) $this->admin->company_id, (int) $account->company_id);
        $this->assertNotSame($password, $account->getRawOriginal('password'));
        $this->assertTrue(Hash::check($password, $account->password));
        $this->assertSame($holder->id, $account->shareHolder->id);

        $this->assertTrue(AuditLog::where('action', 'ShareHolder.account_created')->where('auditable_id', $holder->id)->exists());
        $this->assertStringNotContainsString($password, (string) json_encode(AuditLog::all()->toArray()), 'the audit trail never stores the password');
        $this->assertStringNotContainsString($password, (string) json_encode(DB::table('idempotent_requests')->get()), 'the idempotency store never keeps the password');
        $stored = IdempotentRequest::firstWhere('key', 'create-holder-0001');
        $this->assertTrue(json_decode((string) $stored->response_body, true)['secrets_redacted']);

        // Effective permissions: only the portal permissions, no data scope.
        $access = app(AccessControl::class);
        $this->assertEqualsCanonicalizing(config('permissions.shareholder_portal'), $access->permissionsFor($account->fresh()));
        $this->assertSame([], $access->branchIds($account->fresh()));
    }

    public function test_a_replayed_create_never_returns_the_password_again(): void
    {
        Storage::fake(ShareHolder::DISK);
        $body = $this->payload(['passport_photo' => UploadedFile::fake()->image('same.jpg', 200, 200)]);
        $first = $this->post('/api/v1/capital/share-holders', $body, ['Accept' => 'application/json', 'Idempotency-Key' => 'create-holder-0003'])->assertCreated();
        $this->assertNotNull($first->json('credentials.temporary_password'));

        $replay = $this->post('/api/v1/capital/share-holders', $body, ['Accept' => 'application/json', 'Idempotency-Key' => 'create-holder-0003'])
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertNull($replay->json('credentials'));
        $this->assertTrue($replay->json('secrets_redacted'));
        $this->assertSame(1, ShareHolder::count());
        $this->assertSame(1, Employee::where('account_type', 'shareholder')->count());
    }

    public function test_duplicate_phone_rules_link_staff_and_refuse_linked_logins(): void
    {
        $this->post('/api/v1/capital/share-holders', $this->payload(), ['Accept' => 'application/json'])->assertCreated();
        $employees = Employee::count();

        $this->post('/api/v1/capital/share-holders', $this->payload(['first_name' => 'OTHER', 'share_email' => 'other@example.com']), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.share_mobile.0', "This phone already belongs to shareholder DEVFLOW S SHAREHOLDER's login.");
        $this->assertSame(1, ShareHolder::count());
        $this->assertSame($employees, Employee::count());

        $staff = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'phone' => '0768999002', 'role_id' => $this->admin->company->roles()->where('key', 'finance')->value('id')]);
        $employees = Employee::count();
        $response = $this->post('/api/v1/capital/share-holders', $this->payload(['first_name' => 'STAFF', 'share_mobile' => '0768999002', 'share_email' => 'staff@example.com']), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('account.outcome', 'linked')
            ->assertJsonPath('account.employee_id', $staff->id)
            ->assertJsonPath('credentials', null);
        $this->assertSame($employees, Employee::count(), 'no duplicate account for a staff member');
        $this->assertSame($staff->id, ShareHolder::find($response->json('data.id'))->employee_id);

        $staff = $staff->fresh();
        $this->assertSame('staff', $staff->account_type);
        $this->assertSame('finance', $staff->role->key, 'the staff role is kept');
        $permissions = app(AccessControl::class)->permissionsFor($staff);
        $this->assertContains('accounting.reverse', $permissions);
        $this->assertContains('shareholder.portal', $permissions, 'portal permissions come from the link');

        $this->post('/api/v1/capital/share-holders', $this->payload(['first_name' => 'SHORT', 'share_mobile' => '0777']), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('share_mobile');

        $other = Company::factory()->create();
        Employee::factory()->create(['company_id' => $other->id, 'branch_id' => null, 'phone' => '0768999003']);
        $this->post('/api/v1/capital/share-holders', $this->payload(['first_name' => 'FOREIGN', 'share_mobile' => '0768999003']), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('errors.share_mobile.0', 'This phone already belongs to another login account.');
    }

    public function test_account_and_shareholder_roll_back_together(): void
    {
        $employees = Employee::count();

        try {
            app(ShareholderAccounts::class)->register(
                ['first_name' => 'ROLLBACK', 'last_name' => 'CASE', 'mobile' => '0768999010', 'email' => 'rb@example.com', 'date_of_birth' => null],
                (int) $this->admin->company_id,
                $this->admin,
            );
            $this->fail('the shareholder insert must fail (date_of_birth is required)');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame($employees, Employee::count(), 'no orphan login when the shareholder insert fails');
        $this->assertFalse(Employee::where('phone', '0768999010')->exists());
        $this->assertSame(0, ShareHolder::count());
    }

    public function test_editing_the_phone_keeps_the_login_in_sync_with_duplicate_checks(): void
    {
        $id = $this->post('/api/v1/capital/share-holders', $this->payload(), ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'phone' => '0768999020']);

        $edit = ['first_name' => 'DEVFLOW', 'last_name' => 'SHAREHOLDER', 'share_email' => 'new@example.com', 'share_sex' => 'male', 'share_dob' => '1990-05-05'];
        $this->putJson("/api/v1/capital/share-holders/{$id}", $edit + ['share_mobile' => '0768999020'])
            ->assertUnprocessable()->assertJsonValidationErrors('share_mobile');
        $this->assertSame('0768999001', ShareHolder::find($id)->mobile, 'nothing changed');

        $this->putJson("/api/v1/capital/share-holders/{$id}", $edit + ['share_mobile' => '0768999021'])->assertOk();
        $account = ShareHolder::find($id)->account;
        $this->assertSame(['0768999021', 'new@example.com'], [$account->phone, $account->email]);
    }

    public function test_existing_shareholders_overview_generate_and_command_never_touch_financial_records(): void
    {
        $a = $this->holder('ALPHA', '0768999101');
        $b = $this->holder('BETA', '0768999102');
        $noPhone = $this->holder('NOPHONE', '');
        $bad = $this->holder('BAD', '0777');
        $staff = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'phone' => '0768999104']);
        $staffHolder = $this->holder('STAFFER', '0768999104');
        Capital::create(['company_id' => $this->admin->company_id, 'share_holder_id' => $a->id, 'amount' => 1000, 'pay_method' => 'CASH', 'status' => 'pending']);
        $financial = fn (): array => [Capital::count(), JournalEntry::count(), DB::table('share_transactions')->count(), DB::table('dividend_allocations')->count()];
        $before = $financial();

        $this->getJson('/api/v1/capital/share-holders/accounts')->assertOk()
            ->assertJsonPath('data.totals.total', 5)
            ->assertJsonPath('data.totals.linked', 0)
            ->assertJsonPath('data.totals.not_linked', 5)
            ->assertJsonPath('data.totals.missing_phone', 1)
            ->assertJsonPath('data.totals.invalid_phone', 1)
            ->assertJsonPath('data.totals.phone_conflicts', 1)
            ->assertJsonPath('data.totals.eligible', 3);

        $this->artisan('shareholders:create-accounts', ['--company' => $this->admin->company_id, '--dry-run' => true])
            ->expectsOutputToContain('Dry run: 3 account(s) would be created or linked')
            ->assertSuccessful();
        $this->assertSame(0, ShareHolder::whereNotNull('employee_id')->count(), 'dry run changes nothing');

        $result = $this->postJson('/api/v1/capital/share-holders/accounts/generate', ['share_holder_ids' => [$a->id, $noPhone->id]], ['Idempotency-Key' => 'generate-0001'])
            ->assertOk()->json('data');
        $this->assertCount(1, $result['created']);
        $this->assertSame($a->id, $result['created'][0]['share_holder_id']);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringNotContainsString($result['created'][0]['temporary_password'], (string) json_encode(DB::table('idempotent_requests')->get()));

        $this->artisan('shareholders:create-accounts', ['--company' => $this->admin->company_id])
            ->expectsOutputToContain('1 created, 1 linked to staff logins, 2 skipped.')
            ->assertSuccessful();

        $this->assertNotNull($b->fresh()->employee_id);
        $this->assertSame($staff->id, $staffHolder->fresh()->employee_id);
        $this->assertNull($bad->fresh()->employee_id);
        $this->assertSame($before, $financial(), 'capital, journals, shares and dividends untouched');

        $this->getJson('/api/v1/capital/share-holders/accounts')->assertOk()->assertJsonPath('data.totals.linked', 3)->assertJsonPath('data.totals.eligible', 0);
    }

    public function test_migrations_keep_existing_employees_as_staff_and_add_the_shareholder_role_per_company(): void
    {
        $existing = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id])->fresh();
        $this->assertSame(['staff', false], [$existing->account_type, $existing->must_change_password]);

        $company = Company::factory()->create();
        $migration = require database_path('migrations/2026_09_16_100001_add_shareholder_role_and_portal_permissions.php');
        $migration->up();
        $migration->up();

        $role = DB::table('roles')->where('company_id', $company->id)->where('key', 'shareholder')->first();
        $this->assertNotNull($role);
        $this->assertEqualsCanonicalizing(config('permissions.shareholder_portal'), DB::table('role_permissions')->where('role_id', $role->id)->pluck('permission')->all());
        $this->assertSame(1, DB::table('roles')->where('company_id', $company->id)->where('key', 'shareholder')->count());
    }

    public function test_reset_temporary_password_and_login_status_are_admin_actions(): void
    {
        $id = $this->post('/api/v1/capital/share-holders', $this->payload(), ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $holder = ShareHolder::find($id);
        $account = $holder->account;
        $account->forceFill(['must_change_password' => false])->save();
        $account->createToken('web');

        $password = $this->postJson("/api/v1/capital/share-holders/{$id}/account/reset-password")->assertOk()
            ->assertJsonPath('data.must_change_password', true)->json('credentials.temporary_password');
        $this->assertTrue(Hash::check($password, $account->fresh()->password));
        $this->assertSame(0, $account->tokens()->count());
        $this->assertTrue(AuditLog::where('action', 'ShareHolder.password_reset')->exists());

        $this->postJson("/api/v1/capital/share-holders/{$id}/account/status", ['active' => false])->assertOk()->assertJsonPath('data.status', 'blocked');
        $this->postJson('/api/v1/auth/login', ['phone' => '0768999001', 'password' => $password])->assertUnprocessable();
        $this->postJson("/api/v1/capital/share-holders/{$id}/account/status", ['active' => true])->assertOk()->assertJsonPath('data.status', 'active');

        $teller = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'role_id' => $this->admin->company->roles()->where('key', 'teller')->value('id')]);
        Sanctum::actingAs($teller);
        $this->postJson("/api/v1/capital/share-holders/{$id}/account/reset-password")->assertForbidden();
        $this->getJson('/api/v1/capital/share-holders/accounts')->assertForbidden();
    }
}

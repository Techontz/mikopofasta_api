<?php

namespace Tests\Feature\Api;

use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Loan;
use App\Services\AccessControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_logs_in_with_phone_and_password_and_receives_token_and_permissions(): void
    {
        $admin = $this->signInAdmin();
        auth()->forgetGuards();

        $response = $this->postJson('/api/v1/auth/login', ['phone' => $admin->phone, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.role.key', 'super_admin')
            ->assertJsonStructure(['token', 'user' => ['id', 'full_name', 'company', 'branch', 'permissions', 'branch_ids']]);

        $this->withToken($response->json('token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user', null)->assertJsonPath('data.phone', $admin->phone);
    }

    public function test_wrong_password_and_inactive_accounts_are_refused(): void
    {
        $admin = $this->signInAdmin();
        auth()->forgetGuards();

        $this->postJson('/api/v1/auth/login', ['phone' => $admin->phone, 'password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('phone');

        $admin->update(['status' => 'blocked']);
        $this->postJson('/api/v1/auth/login', ['phone' => $admin->phone, 'password' => 'password'])->assertUnprocessable();
    }

    public function test_login_stats_are_public_counts(): void
    {
        $this->getJson('/api/v1/auth/login-stats')->assertOk()->assertJsonPath('data.active_loans', 0)->assertJsonPath('data.on_time_repayment', null);

        Loan::factory()->count(3)->create(['status' => LoanStatus::Active]);
        Loan::factory()->create(['status' => LoanStatus::Overdue]);
        Loan::factory()->create(['status' => LoanStatus::Closed]);
        Branch::query()->update(['status' => 'inactive']);
        Branch::factory()->count(2)->create();

        $this->getJson('/api/v1/auth/login-stats')->assertOk()->assertJsonPath('data.branches', 2)->assertJsonPath('data.active_loans', 4)->assertJsonPath('data.on_time_repayment', 75);
    }

    public function test_api_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_role_permissions_and_branch_scope(): void
    {
        $admin = $this->signInAdmin();
        $otherBranch = Branch::factory()->create(['company_id' => $admin->company_id]);
        $teller = Employee::factory()->create([
            'company_id' => $admin->company_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->company->roles()->where('key', 'teller')->value('id'),
        ]);
        $access = app(AccessControl::class);

        $this->assertTrue($teller->can('payments.cash'));
        $this->assertFalse($teller->can('loans.disburse'));
        $this->assertTrue($admin->can('capital.manage'));
        $this->assertSame([$admin->branch_id], $access->branchIds($teller));
        $this->assertNull($access->branchIds($admin));
        $this->assertNotContains($otherBranch->id, $access->branchIds($teller));
    }
}

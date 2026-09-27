<?php

namespace Tests\Feature\Api\Shareholders;

use App\Models\AccountingPeriod;
use App\Models\BranchPeriodResult;
use App\Models\Employee;
use App\Models\HrmSetting;
use App\Models\ShareHolder;
use App\Services\AccessControl;
use App\Services\Hrm\PayrollEngine;
use App\Services\Shareholders\ShareholderAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Shareholder portal logins are not staff: they never appear in or affect HRM, payroll, commission, messaging or officer
 * lists, and staff tools cannot turn them into staff (role assignment, privileges).
 */
class ShareholderStaffExclusionTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    private Employee $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        Sanctum::actingAs($this->admin);
        HrmSetting::forCompany($this->admin->company_id)->update(['commission_pool_percent' => 10, 'zone_override_percent' => 5, 'staff_fund_percent' => 10]);

        $holder = ShareHolder::create(['company_id' => $this->admin->company_id, 'first_name' => 'PORTAL', 'last_name' => 'ONLY', 'mobile' => '0768999301', 'email' => 'portal@example.com', 'date_of_birth' => '1990-01-01']);
        $this->account = app(ShareholderAccounts::class)->provision($holder, $this->admin)['account'];

        // Worst case: bad data places the portal login in a branch with an eligible salary — it must still be excluded.
        $this->account->forceFill(['branch_id' => $this->admin->branch_id, 'must_change_password' => false])->save();
        $this->account->salaryInfo()->create(['salary' => 900000, 'salary_type' => 'branch', 'commission_eligible' => true, 'payment_method' => 'bank', 'account_name' => 'NMB', 'account_number' => '1', 'fee' => 0]);
    }

    private function staff(int $salary): Employee
    {
        $employee = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'role_id' => $this->admin->company->roles()->where('key', 'loan_officer')->value('id')]);
        $employee->salaryInfo()->create(['salary' => $salary, 'salary_type' => 'branch', 'commission_eligible' => true, 'payment_method' => 'bank', 'account_name' => 'NMB', 'account_number' => '2', 'fee' => 0]);

        return $employee;
    }

    public function test_excluded_from_payroll_preview_and_commission_eligibility(): void
    {
        $officer = $this->staff(300000);

        $preview = app(PayrollEngine::class)->preview((int) $this->admin->company_id, now()->startOfMonth()->toImmutable());
        $ids = $preview->pluck('employee_id')->all();
        $this->assertContains($officer->id, $ids);
        $this->assertNotContains($this->account->id, $ids);

        $period = AccountingPeriod::create(['company_id' => $this->admin->company_id, 'period_start' => now()->subMonthNoOverflow()->startOfMonth(), 'period_end' => now()->subMonthNoOverflow()->endOfMonth(), 'status' => 'closed']);
        BranchPeriodResult::create(['accounting_period_id' => $period->id, 'branch_id' => $this->admin->branch_id, 'net_profit' => 1000000, 'distributable_profit' => 1000000, 'commission_eligible' => true]);
        $month = now()->subMonthNoOverflow()->format('Y-m');

        $this->postJson('/api/v1/hrm/commission/calculate', ['period' => $month])->assertOk();
        $branch = collect($this->getJson("/api/v1/hrm/commission?period={$month}")->assertOk()->json('data.branches'))->firstWhere('branch_id', $this->admin->branch_id);
        $staffIds = collect($branch['staff'])->pluck('employee_id')->all();
        $this->assertSame([$officer->id], $staffIds, 'the whole staff pool goes to real staff');
        $this->assertEquals(95000, collect($branch['staff'])->firstWhere('employee_id', $officer->id)['amount'], 'C4: staff share 95% of the pool (no zone manager)');
    }

    public function test_excluded_from_staff_lists_options_messaging_and_staff_tools(): void
    {
        $officer = $this->staff(200000);

        $staffIds = collect($this->getJson('/api/v1/hrm/staff')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($officer->id, $staffIds);
        $this->assertNotContains($this->account->id, $staffIds);

        $this->assertNotContains((string) $this->account->id, collect($this->getJson('/api/v1/options/employees')->assertOk()->json('data'))->pluck('value')->all());
        $this->assertNotContains((string) $this->account->id, collect($this->getJson('/api/v1/messages/contacts')->assertOk()->json('data'))->pluck('value')->all());
        $this->getJson("/api/v1/hrm/staff/{$this->account->id}")->assertNotFound();
        $this->getJson("/api/v1/hrm/staff/{$this->account->id}/privileges")->assertNotFound();

        $admin = $this->admin->company->roles()->where('key', 'admin')->value('id');
        $this->putJson("/api/v1/settings/employees/{$this->account->id}/role", ['role_id' => $admin])->assertUnprocessable();
        $shareholderRole = $this->admin->company->roles()->where('key', 'shareholder')->value('id');
        $this->putJson("/api/v1/settings/employees/{$officer->id}/role", ['role_id' => $shareholderRole])->assertUnprocessable();

        // Even a stray override or a staff permission added to the shareholder role grants a portal login nothing more.
        $this->account->permissionOverrides()->create(['permission' => 'capital.manage', 'granted' => true]);
        $this->account->role->permissions()->create(['permission' => 'loans.approve_manager']);
        $this->assertEqualsCanonicalizing(config('permissions.shareholder_portal'), app(AccessControl::class)->permissionsFor($this->account->fresh()));

        // A staff member without a shareholder link never receives portal permissions, not even the Super Admin.
        $this->assertSame([], array_values(array_intersect(config('permissions.shareholder_portal'), app(AccessControl::class)->permissionsFor($this->admin->fresh()))));
        $this->assertSame([], array_values(array_intersect(config('permissions.shareholder_portal'), app(AccessControl::class)->permissionsFor($officer->fresh()))));
        $this->getJson('/api/v1/portal/shareholder/dashboard')->assertForbidden();
    }
}

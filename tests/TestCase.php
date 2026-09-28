<?php

namespace Tests;

use App\Enums\Account;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Services\AccessControl;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create a company with one branch and sign in as its admin.
     */
    protected function signInAdmin(): Employee
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->create(['company_id' => $company->id]);
        app(AccessControl::class)->seedRoles($company);
        $admin = Employee::factory()->admin()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'role_id' => $company->roles()->where('key', 'super_admin')->value('id'),
        ]);

        $this->actingAs($admin);

        return $admin;
    }

    /**
     * Put money in the accounts payroll is paid from — the branch INTEREST A/C (branch staff) and the COMPANY ACCOUNT
     * (HQ staff) — because a payroll that would leave either, or Operation Income, below zero is refused.
     */
    protected function fundPayroll(Employee $admin, float $interest = 10000000, float $company = 10000000): void
    {
        $ledger = app(Ledger::class);
        if ($interest > 0) {
            $ledger->openingBalance($admin->company_id, Account::Interest, $interest, branch: $admin->branch_id);
        }
        if ($company > 0) {
            $ledger->openingBalance($admin->company_id, Account::Company, $company);
        }
    }
}

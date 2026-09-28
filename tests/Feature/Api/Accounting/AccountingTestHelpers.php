<?php

namespace Tests\Feature\Api\Accounting;

use App\Enums\Account;
use App\Models\Employee;
use App\Services\Ledger;
use Carbon\CarbonImmutable;

/**
 * Shared fixtures for the accounting API tests.
 */
trait AccountingTestHelpers
{
    /**
     * @param  list<string>  $extraPermissions
     */
    protected function employeeWithRole(Employee $admin, string $roleKey, array $extraPermissions = [], ?int $branchId = null): Employee
    {
        $role = $admin->company->roles()->where('key', $roleKey)->firstOrFail();
        foreach ($extraPermissions as $permission) {
            $role->permissions()->firstOrCreate(['permission' => $permission]);
        }

        return Employee::factory()->create([
            'company_id' => $admin->company_id,
            'branch_id' => $branchId ?? $admin->branch_id,
            'role_id' => $role->id,
        ]);
    }

    /**
     * Post a repayment-shaped entry: interest with a reserve cut, fee and penalty income.
     */
    protected function postIncome(int $companyId, int $branchId, float $interest, float $reserve, float $fee, float $penalty, string $date): void
    {
        app(Ledger::class)->journal($companyId, 'LOAN RETURN TEST', [
            ['account' => Account::Interest, 'branch' => $branchId, 'debit' => $interest - $reserve],
            ['account' => Account::Reserve, 'branch' => $branchId, 'debit' => $reserve],
            ['account' => Account::InterestIncome, 'branch' => $branchId, 'credit' => $interest],
            ['account' => Account::LoanFee, 'branch' => $branchId, 'debit' => $fee],
            ['account' => Account::FeeIncome, 'branch' => $branchId, 'credit' => $fee],
            ['account' => Account::Penalty, 'branch' => $branchId, 'debit' => $penalty],
            ['account' => Account::PenaltyIncome, 'branch' => $branchId, 'credit' => $penalty],
        ], null, CarbonImmutable::parse($date), $branchId);
    }

    protected function postExpense(int $companyId, int $branchId, float $amount, string $date): void
    {
        app(Ledger::class)->transfer(
            $companyId,
            ['account' => Account::Interest, 'branch' => $branchId],
            ['account' => Account::OperatingExpense, 'branch' => $branchId],
            $amount,
            'Expenses: TEST',
            date: CarbonImmutable::parse($date),
        );
    }
}

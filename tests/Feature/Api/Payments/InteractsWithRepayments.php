<?php

namespace Tests\Feature\Api\Payments;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\Penalty;
use App\Services\Ledger;

/**
 * Fixtures for repayment tests: an active 100,000 loan with 30,000 interest (and optional penalty).
 */
trait InteractsWithRepayments
{
    protected function activeLoan(Employee $admin, float $penalty = 0, ?Branch $branch = null, array $customer = []): Loan
    {
        $branchId = $branch?->id ?? $admin->branch_id;
        $customer = Customer::factory()->create($customer + ['company_id' => $admin->company_id, 'branch_id' => $branchId]);

        $loan = Loan::factory()->create([
            'customer_id' => $customer->id,
            'amount_applied' => 100000,
            'amount_approved' => 100000,
            'interest_amount' => 30000,
            'total_payable' => 130000,
            'restoration' => 130000,
            'insurance' => 0,
            'fee_deduct' => false,
            'loan_fee' => 0,
            'status' => LoanStatus::Active,
            'withdrawn_at' => today()->subDays(10),
            'end_date' => today()->addDays(20),
        ]);
        $loan->schedules()->create(['due_date' => today()->addDays(20), 'amount' => 130000]);

        // The branch originates the loan (LOAN RECEIVABLE is its report), but the cash leaves the HQ PRINCIPAL A/C (no branch).
        app(Ledger::class)->journal($admin->company_id, 'LOAN DISBURSEMENT '.$loan->loan_number, [
            ['account' => Account::LoanReceivable, 'branch' => $branchId, 'debit' => 100000],
            ['account' => Account::Principal, 'credit' => 100000],
        ], $loan);

        if ($penalty > 0) {
            Penalty::create(['company_id' => $admin->company_id, 'branch_id' => $branchId, 'customer_id' => $customer->id, 'loan_id' => $loan->id, 'amount' => $penalty, 'penalty_date' => today()->subDays(2)]);
        }

        return $loan->fresh();
    }

    protected function employeeWithRole(Employee $admin, string $role, ?int $branchId = null): Employee
    {
        return Employee::factory()->create([
            'company_id' => $admin->company_id,
            'branch_id' => $branchId ?? $admin->branch_id,
            'role_id' => $admin->company->roles()->where('key', $role)->value('id'),
        ]);
    }

    protected function balance(Employee $admin, Account $account, ?int $branchId = null, ?int $bankAccountId = null, ?int $employeeId = null): float
    {
        return app(Ledger::class)->balance($admin->company_id, $account, $branchId, $bankAccountId, employee: $employeeId);
    }
}

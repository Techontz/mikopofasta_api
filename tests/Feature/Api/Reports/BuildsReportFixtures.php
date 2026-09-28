<?php

namespace Tests\Feature\Api\Reports;

use App\Enums\Duration;
use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\Penalty;
use App\Services\LoanService;
use Carbon\CarbonImmutable;

/**
 * Report fixtures built through LoanService (real schedules, ledger postings and Principal → Penalty → Interest splits).
 * Standard loan: 100,000 principal, 30,000 interest, one weekly instalment of 130,000, 5,000 fee deducted.
 */
trait BuildsReportFixtures
{
    protected Employee $admin;

    /**
     * @param  array<string, mixed>  $customer
     */
    protected function cashedOutLoan(int $daysAgo, ?Branch $branch = null, array $customer = [], float $principal = 100000, int $sessions = 1): Loan
    {
        $branchId = $branch?->id ?? $this->admin->branch_id;
        $owner = Customer::factory()->create($customer + ['company_id' => $this->admin->company_id, 'branch_id' => $branchId]);
        $interest = round($principal * 0.3, 2);

        $loan = Loan::factory()->create([
            'customer_id' => $owner->id,
            'employee_id' => $this->admin->id,
            'amount_applied' => $principal,
            'amount_approved' => $principal,
            'interest_amount' => $interest,
            'total_payable' => $principal + $interest,
            'restoration' => round(($principal + $interest) / $sessions, 2),
            'insurance' => 0,
            'sessions' => $sessions,
            'duration' => Duration::Weekly,
            'status' => LoanStatus::AwaitingDisbursement,
        ]);

        app(LoanService::class)->withdraw($loan, CarbonImmutable::today()->subDays($daysAgo), $this->admin);

        return $loan->fresh();
    }

    protected function repay(Loan $loan, float $amount, int $daysAgo = 0): void
    {
        app(LoanService::class)->deposit($loan->fresh(), $amount, CarbonImmutable::today()->subDays($daysAgo), 'CASH', $this->admin);
    }

    protected function penalise(Loan $loan, float $amount, int $daysAgo = 1): Penalty
    {
        return Penalty::create([
            'company_id' => $loan->company_id, 'branch_id' => $loan->branch_id, 'customer_id' => $loan->customer_id,
            'loan_id' => $loan->id, 'amount' => $amount, 'penalty_date' => CarbonImmutable::today()->subDays($daysAgo)->toDateString(),
        ]);
    }

    protected function employeeWithRole(string $role, ?int $branchId = null): Employee
    {
        return Employee::factory()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $branchId ?? $this->admin->branch_id,
            'role_id' => $this->admin->company->roles()->where('key', $role)->value('id'),
        ]);
    }

    protected function otherBranch(): Branch
    {
        return Branch::factory()->create(['company_id' => $this->admin->company_id]);
    }
}

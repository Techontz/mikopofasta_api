<?php

namespace Tests\Feature\Api\Expenses;

use App\Enums\Account;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Loan;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanFeeIncomeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_income_lists_loan_fee_postings_filtered_by_branch_and_date(): void
    {
        $admin = $this->signInAdmin();
        $otherBranch = Branch::factory()->create(['company_id' => $admin->company_id]);
        $ledger = app(Ledger::class);

        $customer = Customer::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id]);
        $loan = Loan::factory()->create(['customer_id' => $customer->id, 'amount_approved' => 100000]);
        $ledger->journal($admin->company_id, 'Loan fee', [
            ['account' => Account::LoanFee, 'branch' => $admin->branch_id, 'debit' => 5000],
            ['account' => Account::FeeIncome, 'branch' => $admin->branch_id, 'credit' => 5000],
        ], $loan);

        $otherCustomer = Customer::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $otherBranch->id]);
        $otherLoan = Loan::factory()->create(['customer_id' => $otherCustomer->id, 'amount_approved' => 200000]);
        $ledger->journal($admin->company_id, 'Loan fee', [
            ['account' => Account::LoanFee, 'branch' => $otherBranch->id, 'debit' => 7000],
            ['account' => Account::FeeIncome, 'branch' => $otherBranch->id, 'credit' => 7000],
        ], $otherLoan, today()->subDays(3));

        $this->getJson('/api/v1/loan-fees/income')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer', $customer->full_name)
            ->assertJsonPath('data.0.loan_approved', 100000)
            ->assertJsonPath('total', 5000);

        $range = ['from' => today()->subWeek()->toDateString(), 'to' => today()->toDateString()];
        $this->getJson('/api/v1/loan-fees/income?'.http_build_query($range + ['branch_id' => 'all']))->assertJsonPath('total', 12000);
        $this->getJson('/api/v1/loan-fees/income?'.http_build_query($range + ['branch_id' => $otherBranch->id]))->assertJsonPath('total', 7000)->assertJsonPath('data.0.branch', $otherBranch->name);

        $teller = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'role_id' => $admin->company->roles()->where('key', 'teller')->value('id')]);
        $this->actingAs($teller)->getJson('/api/v1/loan-fees/income')->assertForbidden();
    }
}

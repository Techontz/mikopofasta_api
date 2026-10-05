<?php

namespace Tests\Feature\Api\Loans;

use App\Enums\LoanStatus;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceCategory;
use App\Services\LegacyImports\SpreadsheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Mobile money disbursement file (first_name, last_name, phone_number, amount, payment_details) for customer loans
 * ready to pay out and customer salary advances approved today.
 */
class MobileDisbursementFileTest extends TestCase
{
    use RefreshDatabase;

    public function test_loan_file_lists_unpaid_loans_with_the_amount_to_send(): void
    {
        $admin = $this->signInAdmin();
        $customer = Customer::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'first_name' => 'Asha', 'last_name' => 'Juma', 'phone' => '0754000123']);
        $wallet = Customer::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'first_name' => 'Baraka', 'last_name' => 'Ali', 'payment_method' => 'mno', 'wallet_number' => '255765000111']);
        $pending = Loan::factory()->create(['customer_id' => $customer->id, 'amount_approved' => 100000, 'fee_deduct' => true, 'loan_fee' => 5000, 'status' => LoanStatus::PendingFinance]);
        Loan::factory()->create(['customer_id' => $wallet->id, 'amount_approved' => 50000, 'fee_deduct' => false, 'loan_fee' => 2000, 'status' => LoanStatus::AwaitingDisbursement]);
        Loan::factory()->create(['customer_id' => $customer->id, 'amount_approved' => 70000, 'status' => LoanStatus::Active]);

        $rows = $this->rows($this->get('/api/v1/loans/disbursement-file'));

        $this->assertSame(['first_name', 'last_name', 'phone_number', 'amount', 'payment_details'], $rows[0]);
        $this->assertCount(3, $rows, 'the active loan is already paid out');
        $this->assertContains(['Asha', 'Juma', '0754000123', '95000', "Loan {$pending->loan_number}"], $rows);
        $this->assertSame(['Baraka', 'Ali', '255765000111', '50000'], array_slice($rows[1], 0, 4), 'the mobile wallet is used when the customer is paid by mobile money');

        $this->assertCount(2, $this->rows($this->get('/api/v1/loans/disbursement-file?status=pending_finance')));
        $this->getJson('/api/v1/loans/disbursement-file?status=active')->assertJsonValidationErrors('status');
    }

    public function test_salary_advance_file_lists_advances_approved_today(): void
    {
        $admin = $this->signInAdmin();
        $category = SalaryAdvanceCategory::create(['company_id' => $admin->company_id, 'name' => 'WATUMISHI', 'interest_rate' => 20, 'amount_from' => 10000, 'amount_to' => 30000, 'fee' => 200]);
        $customer = Customer::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'first_name' => 'Neema', 'last_name' => 'Moshi', 'phone' => '0713000222']);
        $advance = fn (string $status, $approvedAt): SalaryAdvance => SalaryAdvance::create([
            'company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'customer_id' => $customer->id, 'salary_advance_category_id' => $category->id,
            'amount' => 20000, 'interest_rate' => 20, 'total_payable' => 24000, 'fee' => 200, 'status' => $status, 'approved_at' => $approvedAt,
        ]);
        $today = $advance('active', now());
        $advance('active', now()->subDay());
        $advance('pending', null);

        $rows = $this->rows($this->get('/api/v1/salary-advance/approved/disbursement-file'));

        $this->assertSame([
            ['first_name', 'last_name', 'phone_number', 'amount', 'payment_details'],
            ['Neema', 'Moshi', '0713000222', '20000', "Salary Advance #{$today->id}"],
        ], $rows);
    }

    /**
     * @return list<list<string>>
     */
    private function rows(TestResponse $response): array
    {
        $response->assertOk()->assertDownload();
        $path = tempnam(sys_get_temp_dir(), 'test').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        try {
            return array_map(fn (array $row): array => array_map('strval', $row), SpreadsheetReader::read($path, 'file.xlsx'));
        } finally {
            @unlink($path);
        }
    }
}

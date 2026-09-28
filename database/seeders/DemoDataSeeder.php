<?php

namespace Database\Seeders;

use App\Enums\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Capital;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\ExpenseRequest;
use App\Models\ExpenseType;
use App\Models\FloatTransfer;
use App\Models\Guarantor;
use App\Models\LoanCategory;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceCategory;
use App\Models\Saving;
use App\Models\ShareHolder;
use App\Services\Ledger;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Fictitious customers, staff and loans so every screen has realistic content.
 */
class DemoDataSeeder extends Seeder
{
    public function run(Ledger $ledger, LoanService $loans): void
    {
        $company = Company::where('phone', config('demo.admin_phone'))->firstOrFail();
        $admin = Employee::where('phone', config('demo.admin_phone'))->firstOrFail();
        $branches = $company->branches()->get();
        $today = CarbonImmutable::today();

        $shareHolder = ShareHolder::firstOrCreate(['company_id' => $company->id, 'first_name' => 'JOHN', 'last_name' => 'SHAREHOLDER'], [
            'mobile' => '0777000000', 'email' => 'shareholder@example.com', 'gender' => 'male', 'date_of_birth' => '1985-01-01',
        ]);
        $capital = Capital::create(['company_id' => $company->id, 'share_holder_id' => $shareHolder->id, 'amount' => 50000000, 'pay_method' => 'BANK', 'receipt_number' => '1001']);
        $ledger->transfer($company, ['account' => Account::Capital], ['account' => Account::Company], 50000000, 'CAPITAL', $capital, date: $today->subMonths(4));

        $branches->each(function (Branch $branch) use ($company, $ledger, $today): void {
            $float = FloatTransfer::create(['company_id' => $company->id, 'type' => 'company_to_branch', 'to_branch_id' => $branch->id, 'amount' => 6000000, 'status' => 'approved', 'transfer_date' => $today->subMonths(4)]);
            $ledger->transfer($company, ['account' => Account::Company], ['account' => Account::Principal, 'branch' => $branch], 6000000, 'FLOAT', $float);

            foreach (['branch_manager', 'loan_officer', 'teller'] as $roleKey) {
                if ($branch->name === config('demo.branch')) {
                    continue;
                }
                Employee::factory()->create([
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'zone_id' => $branch->zone_id,
                    'role_id' => $company->roles()->where('key', $roleKey)->value('id'),
                ]);
            }
        });

        foreach ($company->zones()->where('name', '!=', config('demo.zone'))->get() as $zone) {
            Employee::factory()->create(['company_id' => $company->id, 'branch_id' => $zone->branches()->value('id'), 'zone_id' => $zone->id, 'position' => 'zone', 'role_id' => $company->roles()->where('key', 'zone_manager')->value('id')]);
        }

        $categories = LoanCategory::where('company_id', $company->id)->get()->keyBy('name');
        $typeIds = CustomerCategory::where('company_id', $company->id)->pluck('id', 'code');
        $scenarios = [
            // [category, amount, sessions, withdrawn days ago, repayments made, final status hint]
            ['WAJASILIAMALI', 200000, 3, 10, 1, null],
            ['WAJASILIAMALI', 100000, 1, 40, 1, null],
            ['WATUMISHI 2', 600000, 3, 45, 1, null],
            ['NEW WATUMISHI 1', 300000, 2, 100, 0, null],
            ['VIKUNDI 1', 400000, 3, 5, 0, null],
            ['GROUP LOAN', 800000, 4, 20, 2, null],
            ['WAJASILIAMALI', 150000, 2, 0, 0, 'disbursed'],
            ['WAJASILIAMALI', 250000, 3, 0, 0, 'pending'],
            ['NEW WATUMISHI 2', 700000, 4, 0, 0, 'pending'],
        ];

        foreach (range(0, 29) as $index) {
            $branch = $branches[$index % $branches->count()];
            $customer = Customer::factory()->create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'employee_id' => $branch->employees()->inRandomOrder()->value('id'),
                'region_id' => $branch->region_id,
                'work_status' => $index % 3 === 0 ? 'ent' : 'ser',
                // Customer type → loan category: a borrower's type is the customer type of its loan's category.
                'customer_category_id' => isset($scenarios[$index % 12])
                    ? $categories[$scenarios[$index % 12][0]]->customer_category_id
                    : $typeIds[$index % 3 === 0 ? 'WATUMISHI_WA_UMMA' : 'WAJASIRIAMALI'] ?? null,
                'customer_type' => $index % 3 === 0 ? 'binafsi' : ($index % 5 === 0 ? 'group' : 'binafsi'),
                'created_at' => $today->subDays(120 - $index),
            ]);

            Guarantor::create([
                'customer_id' => $customer->id,
                'first_name' => strtoupper(fake()->firstName()),
                'middle_name' => strtoupper(fake()->firstName()),
                'last_name' => strtoupper(fake()->lastName()),
                'phone' => fake()->numerify('07########'),
                'gender' => 'female',
                'marital_status' => 'Married',
                'id_number' => fake()->numerify('#########'),
                'relationship' => 'RAFIKI',
                'region_id' => $customer->region_id,
                'district' => $customer->district,
                'ward' => $customer->ward,
                'street' => $customer->street,
            ]);

            if (! isset($scenarios[$index % 12])) {
                continue;
            }

            [$categoryName, $amount, $sessions, $daysAgo, $repayments, $hint] = $scenarios[$index % 12];
            $category = $categories[$categoryName];
            $loan = $loans->apply($customer, [
                'loan_category_id' => $category->id,
                'amount_applied' => $amount,
                'sessions' => $sessions,
                'formula' => 'SIMPLE',
                'fee_deduct' => $category->fee_deduct,
                'reason' => 'BIASHARA',
            ], $admin);

            $loan->guarantors()->save(Guarantor::where('customer_id', $customer->id)->first());
            $loan->collaterals()->create(['name' => 'SMART PHONE', 'type' => 'TECNO', 'location' => $customer->district, 'value' => 70000]);

            if ($hint === 'pending') {
                continue;
            }

            $loans->approve($loan, $amount);
            if ($hint === 'disbursed') {
                continue;
            }

            $withdrawn = $today->subDays($daysAgo);
            $loans->withdraw($loan->fresh(), $withdrawn, $admin);
            for ($payment = 1; $payment <= $repayments; $payment++) {
                $loans->deposit($loan->fresh(), (float) $loan->fresh()->restoration, $withdrawn->addDays(7 * $payment - 1)->min($today), 'CASH', $admin);
            }
        }

        $loans->applyPenaltiesAndDefaults($today);

        $this->seedOperations($company, $ledger, $today);
    }

    /**
     * Staff salaries, salary advances, savings, agent transactions, expenses and transfers.
     */
    private function seedOperations(Company $company, Ledger $ledger, CarbonImmutable $today): void
    {
        $branches = $company->branches()->get();
        $bank = BankAccount::where('company_id', $company->id)->firstOrFail();

        $demoNumbers = array_column(config('demo.accounts'), 'employee_number');

        Employee::where('company_id', $company->id)->where('phone', '!=', config('demo.admin_phone'))->each(function (Employee $employee, int $index) use ($demoNumbers): void {
            if (! in_array($employee->employee_number, $demoNumbers, true)) {
                $employee->forceFill(['employee_number' => sprintf('MK-%03d%d', $index + 1, now()->year)])->save();
            }
            EmployeeSalary::create([
                'employee_id' => $employee->id,
                'salary' => [300000, 400000, 250000][$index % 3],
                'account_name' => ['NMB', 'CRDB'][$index % 2],
                'account_number' => fake()->numerify('##########'),
            ]);
        });

        $ledger->transfer($company, ['account' => Account::Company], ['account' => Account::Bank, 'bank' => $bank], 2000000, 'BANK DEPOSIT');
        $ledger->transfer($company, ['account' => Account::Bank, 'bank' => $bank], ['account' => Account::HqSalaryAdvance], 500000, 'SALARY ADVANCE ACC');

        $category = SalaryAdvanceCategory::where('company_id', $company->id)->firstOrFail();
        $employedCustomers = Customer::where('company_id', $company->id)->where('work_status', 'ent')->take(4)->get();
        foreach ($employedCustomers as $index => $customer) {
            $amount = [20000, 25000, 30000, 15000][$index];
            SalaryAdvance::create([
                'company_id' => $company->id,
                'branch_id' => $customer->branch_id,
                'customer_id' => $customer->id,
                'salary_advance_category_id' => $category->id,
                'amount' => $amount,
                'interest_rate' => $category->interest_rate,
                'total_payable' => $amount * (1 + $category->interest_rate / 100),
                'fee' => $category->fee,
                'status' => $index < 2 ? 'pending' : 'active',
                'approved_at' => $index < 2 ? null : $today->subDays(3),
            ]);
            $customer->update(['bank_account_name' => ['NMB', 'CRDB'][$index % 2], 'bank_password' => fake()->numerify('####')]);
        }

        foreach (Customer::where('company_id', $company->id)->take(5)->get() as $index => $customer) {
            $saving = Saving::create([
                'company_id' => $company->id,
                'branch_id' => $customer->branch_id,
                'customer_id' => $customer->id,
                'type' => 'deposit',
                'description' => 'SAVING DEPOSIT',
                'amount' => 10000 * ($index + 1),
                'transaction_date' => $today,
            ]);
            $ledger->transfer($company, ['account' => Account::SavingsDeposits, 'branch' => $customer->branch_id], ['account' => Account::HqSaving, 'branch' => $customer->branch_id], (float) $saving->amount, 'SAVING DEPOSIT', $saving);
        }

        $expenseType = ExpenseType::where('company_id', $company->id)->where('scope', 'branch')->firstOrFail();
        foreach ($branches->take(2) as $branch) {
            ExpenseRequest::create([
                'company_id' => $company->id,
                'scope' => 'branch',
                'branch_id' => $branch->id,
                'expense_type_id' => $expenseType->id,
                'amount' => 50000,
                'description' => 'Bili ya umeme',
                'status' => 'pending',
                'request_date' => $today,
            ]);
        }

        FloatTransfer::create([
            'company_id' => $company->id,
            'type' => 'branch_to_branch',
            'from_branch_id' => $branches[1]->id,
            'to_branch_id' => $branches[4]->id,
            'amount' => 100000,
            'status' => 'pending',
            'transfer_date' => $today,
        ]);

        Customer::where('company_id', $company->id)->whereIn('status', ['close', 'out'])->take(3)->update(['is_marked' => true]);
    }
}

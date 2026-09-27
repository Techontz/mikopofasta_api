<?php

namespace Database\Seeders;

use App\Enums\Duration;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Models\ExpenseType;
use App\Models\Group;
use App\Models\InterestFormula;
use App\Models\LoanCategory;
use App\Models\PaymentMode;
use App\Models\Region;
use App\Models\SalaryAdvanceCategory;
use App\Models\StaffLoanCategory;
use App\Models\StaffSalaryAdvanceCategory;
use App\Models\Zone;
use App\Services\AccessControl;
use Illuminate\Database\Seeder;

/**
 * Configuration observed on the live system (regions, products, categories, settings).
 * Contains no customer or staff personal data.
 */
class MasterDataSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private const REGIONS = ['Mbeya', 'Mwanza', 'Chato', 'Geita', 'Dar es salaam', 'Ruvuma', 'Kusini Unguja', 'Kaskazini Unguja', 'Tanga', 'Tabora', 'Songwe', 'Singida', 'Simiyu', 'Shinyanga', 'Rukwa', 'Pwani', 'Kusini Pemba', 'Kaskazini Pemba', 'Njombe', 'Mtwara', 'Morogoro', 'Mjini Magharibi', 'Mara', 'Manyara', 'Lindi', 'Kilimanjaro', 'Kigoma', 'Katavi', 'Kagera', 'Iringa', 'Dodoma', 'Arusha'];

    public function run(): void
    {
        foreach (self::REGIONS as $index => $name) {
            Region::updateOrCreate(['id' => $index + 1], ['name' => $name]);
        }

        $company = Company::updateOrCreate(['phone' => config('demo.admin_phone')], [
            'name' => 'TEST MFUMO MPYA',
            'registration_number' => '0001',
            'address' => 'ilemela',
            'email' => config('demo.admin_email'),
            'region_id' => $this->region('Mwanza'),
            'loan_fee_mode' => 'product',
            'penalty_type' => 'percentage',
            'penalty_value' => 20,
            'reserve_percent' => 20,
        ]);

        app(AccessControl::class)->seedRoles($company);
        $lakeZone = Zone::firstOrCreate(['company_id' => $company->id, 'name' => 'KANDA YA ZIWA']);
        $southZone = Zone::firstOrCreate(['company_id' => $company->id, 'name' => 'KANDA YA KUSINI']);
        $zoneFor = ['Head office' => $lakeZone, 'Kakonko' => $lakeZone, 'Missenyi' => $lakeZone, 'Lindi' => $southZone, 'NEW KALENGE' => $southZone, 'TEST' => $southZone];

        $branches = collect([
            ['Head office', '0666', 'Mwanza', 'main'],
            ['Kakonko', '0555', 'Kigoma', 'main'],
            ['Missenyi', '0444', 'Kagera', 'main'],
            ['Lindi', '0333', 'Lindi', 'main'],
            ['NEW KALENGE', '0666', 'Mbeya', 'sub'],
            ['TEST', '09989789879', 'Mbeya', 'sub'],
        ])->map(fn (array $row): Branch => Branch::updateOrCreate(
            ['company_id' => $company->id, 'name' => $row[0]],
            ['phone' => $row[1], 'region_id' => $this->region($row[2]), 'type' => $row[3], 'status' => 'active', 'zone_id' => $zoneFor[$row[0]]->id],
        ));

        $admin = Employee::updateOrCreate(['phone' => config('demo.admin_phone')], [
            'company_id' => $company->id,
            'branch_id' => $branches->first()->id,
            'employee_number' => 'MK-0012024',
            'first_name' => 'ADMIN',
            'last_name' => 'M-KOPA',
            'email' => config('demo.admin_email'),
            'username' => 'admin',
            'gender' => 'male',
            'position' => 'admin',
            'role_id' => $company->roles()->where('key', 'super_admin')->value('id'),
            'status' => 'active',
            'password' => config('demo.admin_password'),
        ]);
        foreach (array_keys(Employee::PRIVILEGES) as $privilege) {
            $admin->privileges()->firstOrCreate(['privilege' => $privilege]);
        }
        $this->seedDemoAccounts($company);

        foreach ([['SIMPLE', 'SIMPLE FORMULAR', true], ['FLATRATE', 'FLAT RATE FORMULAR', true], ['REDUCING', 'REDUCING FORMULAR', false]] as [$code, $name, $enabled]) {
            InterestFormula::updateOrCreate(['code' => $code], ['name' => $name, 'is_enabled' => $enabled]);
        }

        $this->seedCustomerTypes($company);
        // Demo products per customer type: WATUMISHI products → Mtumishi wa Umma; WAJASILIAMALI / VIKUNDI / GROUP → Mjasiriamali/Mfanyabiashara.
        $watumishi = $this->customerType($company, 'WATUMISHI_WA_UMMA');
        $wajasiliamali = $this->customerType($company, 'WAJASIRIAMALI');

        $products = [
            // name, customer type, from, to, rate, duration, rep from, rep to, fee deduct, approve, topup, take home, fee, insurance
            ['WAJASILIAMALI', $wajasiliamali, 20000, 2000000, 30, Duration::Weekly, 1, 3, true, 'hq', 50, 70, 5000, 5000],
            ['GROUP LOAN', $wajasiliamali, 600000, 1000000, 20, Duration::Weekly, 1, 5, true, 'hq', 90, 90, 75000, 100000],
            ['VIKUNDI 1', $wajasiliamali, 250000, 500000, 30, Duration::Weekly, 1, 3, true, 'branch', 50, 70, 5000, 25000],
            ['VIKUNDI 2', $wajasiliamali, 500000, 1000000, 30, Duration::Weekly, 1, 8, true, 'hq', 50, 70, 10000, 50000],
            ['WATUMISHI 2', $watumishi, 500000, 1200000, 30, Duration::Monthly, 1, 6, true, 'branch', 50, 70, 10000, 10000],
            ['WATUMISHI 3', $watumishi, 1000000, 3000000, 40, Duration::Monthly, 1, 8, true, 'hq', 50, 70, 10000, 30000],
            ['NEW WATUMISHI 1', $watumishi, 100000, 500000, 20, Duration::Monthly, 1, 4, true, 'branch', 50, 70, 5000, 5000],
            ['NEW WATUMISHI 2', $watumishi, 500000, 1500000, 20, Duration::Monthly, 1, 6, true, 'zone manager', 50, 70, 10000, 10000],
            ['NEW WATUMISHI 3', $watumishi, 1000000, 5000000, 50, Duration::Monthly, 1, 12, true, 'hq', 50, 70, 10000, 20000],
            ['VIP DESK', $watumishi, 1000000, 5000000, 30, Duration::Monthly, 1, 2, false, 'hq', 100, 100, 0, 0],
            ['VVIP DESK', $watumishi, 2000000, 10000000, 30, Duration::Monthly, 1, 2, false, 'hq', 100, 100, 0, 0],
            ['WATUMISHI LOAN', $watumishi, 100000, 500000, 20, Duration::Monthly, 1, 3, true, 'branch', 50, 70, 5000, 5000],
        ];

        foreach ($products as [$name, $customerType, $from, $to, $rate, $duration, $repFrom, $repTo, $feeDeduct, $approve, $topup, $takeHome, $fee, $insurance]) {
            $category = LoanCategory::updateOrCreate(['company_id' => $company->id, 'name' => $name], [
                'customer_category_id' => $customerType->id,
                'amount_from' => $from,
                'amount_to' => $to,
                'interest_rate' => $rate,
                'formula' => 'SIMPLE',
                'duration' => $duration,
                'repayment_from' => $repFrom,
                'repayment_to' => $repTo,
                'fee_deduct' => $feeDeduct,
                'has_penalty' => true,
                'approve_level' => $approve,
                'topup_percent' => $topup,
                'take_home_percent' => $takeHome,
                'fee_type' => 'money',
                'fee_value' => $fee,
                'insurance' => $insurance,
            ]);
            $category->branches()->syncWithoutDetaching($branches->pluck('id'));
        }

        Group::firstOrCreate(['company_id' => $company->id, 'name' => 'WAZURI']);

        foreach (['NMB', 'CRDB'] as $bank) {
            BankAccount::firstOrCreate(['company_id' => $company->id, 'name' => $bank]);
        }

        foreach (['branch' => ['umeme', 'MAJI', 'SODA'], 'bank' => ['MISHAHARA'], 'hq' => ['MAFUTA']] as $scope => $names) {
            foreach ($names as $name) {
                ExpenseType::firstOrCreate(['company_id' => $company->id, 'scope' => $scope, 'name' => $name]);
            }
        }

        PaymentMode::firstOrCreate(['company_id' => $company->id, 'name' => 'M-PESA']);

        SalaryAdvanceCategory::firstOrCreate(['company_id' => $company->id, 'name' => 'WATUMISHI'], ['interest_rate' => 20, 'amount_from' => 10000, 'amount_to' => 30000, 'fee' => 200]);
        StaffLoanCategory::firstOrCreate(['company_id' => $company->id, 'name' => 'TEST1'], ['amount_from' => 1000, 'amount_to' => 10000, 'interest_rate' => 20, 'duration' => 'monthly', 'repayment_from' => 1, 'repayment_to' => 3, 'fee' => 0]);
        StaffSalaryAdvanceCategory::firstOrCreate(['company_id' => $company->id, 'name' => 'SALARY ADVANCE STAFF'], ['amount_from' => 10000, 'amount_to' => 100000, 'fee' => 200]);
    }

    /**
     * One fixed demo login per role (config/demo.php), so every role can be tested with known credentials.
     */
    private function seedDemoAccounts(Company $company): void
    {
        $zone = $company->zones()->where('name', config('demo.zone'))->firstOrFail();
        $branch = $company->branches()->where('name', config('demo.branch'))->firstOrFail();
        $headOffice = $company->branches()->where('name', config('demo.hq_branch'))->firstOrFail();
        $roles = $company->roles()->pluck('id', 'key');

        foreach (config('demo.accounts') as $roleKey => $account) {
            $placement = match ($account['placement']) {
                'branch' => ['branch_id' => $branch->id, 'zone_id' => $branch->zone_id, 'position' => 'employee'],
                'zone' => ['branch_id' => $branch->id, 'zone_id' => $zone->id, 'position' => 'zone'],
                default => ['branch_id' => $headOffice->id, 'zone_id' => null, 'position' => 'hq'],
            };

            Employee::updateOrCreate(['company_id' => $company->id, 'employee_number' => $account['employee_number']], [
                ...$placement,
                'phone' => $account['phone'],
                'first_name' => 'DEMO',
                'last_name' => strtoupper(str_replace('_', ' ', $roleKey)),
                'email' => str_replace('_', '.', $roleKey).'@example.com',
                'username' => 'demo.'.$roleKey,
                'gender' => 'male',
                'role_id' => $roles[$roleKey],
                'status' => 'active',
                'password' => $account['password'],
            ]);
        }
    }

    /**
     * The five customer types (CustomerModuleSeeder) plus the legacy risk level. Customer types carry no loan rules:
     * products and limits live on the loan categories of each customer type.
     */
    private function seedCustomerTypes(Company $company): void
    {
        $customerModule = new CustomerModuleSeeder;
        $customerModule->seedReferenceData();
        $customerModule->seedGeography();
        $customerModule->seedCompany($company);

        foreach (['WATUMISHI_WA_UMMA' => 'low', 'SEKTA_BINAFSI' => 'medium', 'WAJASIRIAMALI' => 'high', 'MWANAFUNZI_CHUO' => 'high', 'MSTAAFU_UMMA' => 'medium'] as $code => $risk) {
            CustomerCategory::where('company_id', $company->id)->where('code', $code)->firstOrFail()->update(['risk_level' => $risk]);
        }
    }

    /**
     * The customer type with the given code.
     */
    private function customerType(Company $company, string $code): CustomerCategory
    {
        return CustomerCategory::where('company_id', $company->id)->where('code', $code)->firstOrFail();
    }

    private function region(string $name): int
    {
        return array_search($name, self::REGIONS, true) + 1;
    }
}

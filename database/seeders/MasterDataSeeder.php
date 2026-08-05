<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MasterData\AccountType;
use App\Models\MasterData\Bank;
use App\Models\MasterData\CustomerType;
use App\Models\MasterData\EmploymentType;
use App\Models\MasterData\LoanType;
use App\Models\MasterData\MaritalStatusOption;
use App\Models\MasterData\MobileMoneyProvider;
use App\Models\MasterData\Occupation;
use App\Models\MasterData\WorkType;
use Illuminate\Database\Seeder;

/**
 * Starting values for the admin-managed lookup lists.
 *
 * These are a STARTING POINT, not a fixed set. Every row here can be renamed,
 * reordered, disabled or removed from the Administration module, and new ones
 * added, without touching code — which is the whole reason these are tables.
 *
 * Values are the ones the legacy system uses, in Swahili where the legacy
 * system uses Swahili: the officers operating this are reading the same words
 * they read today, and translating them would be a change to the business
 * vocabulary dressed up as a migration.
 *
 * `updateOrCreate` on `code`, so re-running the seeder on a live database
 * refreshes the shipped rows without duplicating them or overwriting anything
 * the institution has added.
 */
final class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->fill(LoanType::class, [
            ['code' => 'WATUMISHI', 'name' => 'Watumishi', 'description' => 'Salaried public servants.', 'sort_order' => 1],
            ['code' => 'BIASHARA', 'name' => 'Biashara', 'description' => 'Business and trading customers.', 'sort_order' => 2],
            ['code' => 'KILIMO', 'name' => 'Kilimo', 'description' => 'Agriculture.', 'sort_order' => 3],
            ['code' => 'DHAMANA', 'name' => 'Dhamana', 'description' => 'Secured against collateral.', 'sort_order' => 4],
        ]);

        $this->fill(CustomerType::class, [
            ['code' => 'BINAFSI', 'name' => 'BINAFSI', 'description' => 'Individual customer.', 'sort_order' => 1],
            ['code' => 'KIKUNDI', 'name' => 'KIKUNDI', 'description' => 'Group.', 'sort_order' => 2],
            ['code' => 'TAASISI', 'name' => 'TAASISI', 'description' => 'Institution.', 'sort_order' => 3],
        ]);

        $this->fill(AccountType::class, [
            ['code' => 'LOAN', 'name' => 'LOAN ACCOUNT', 'sort_order' => 1],
            ['code' => 'SAVINGS', 'name' => 'SAVINGS ACCOUNT', 'sort_order' => 2],
        ]);

        $this->fill(WorkType::class, [
            ['code' => 'PERMANENT', 'name' => 'Permanent', 'sort_order' => 1],
            ['code' => 'CONTRACT', 'name' => 'Contract', 'sort_order' => 2],
            ['code' => 'CASUAL', 'name' => 'Casual', 'sort_order' => 3],
        ]);

        $this->fill(EmploymentType::class, [
            ['code' => 'GOVERNMENT', 'name' => 'Government', 'sort_order' => 1],
            ['code' => 'PRIVATE', 'name' => 'Private Sector', 'sort_order' => 2],
            ['code' => 'SELF', 'name' => 'Self Employed', 'sort_order' => 3],
        ]);

        $this->fill(Occupation::class, [
            ['code' => 'TEACHER', 'name' => 'Teacher'],
            ['code' => 'NURSE', 'name' => 'Nurse'],
            ['code' => 'TRADER', 'name' => 'Trader'],
            ['code' => 'FARMER', 'name' => 'Farmer'],
            ['code' => 'DRIVER', 'name' => 'Driver'],
            ['code' => 'TAILOR', 'name' => 'Tailor'],
        ]);

        /* The banks a Tanzanian microfinance customer is most likely to hold an
           account with. The institution adds the rest. */
        $this->fill(Bank::class, [
            ['code' => 'CRDB', 'name' => 'CRDB Bank', 'sort_order' => 1],
            ['code' => 'NMB', 'name' => 'NMB Bank', 'sort_order' => 2],
            ['code' => 'NBC', 'name' => 'NBC Bank', 'sort_order' => 3],
            ['code' => 'EXIM', 'name' => 'Exim Bank'],
            ['code' => 'AZANIA', 'name' => 'Azania Bank'],
        ]);

        $this->fill(MobileMoneyProvider::class, [
            ['code' => 'MPESA', 'name' => 'M-Pesa', 'sort_order' => 1],
            ['code' => 'TIGOPESA', 'name' => 'Mixx by Yas', 'sort_order' => 2],
            ['code' => 'AIRTELMONEY', 'name' => 'Airtel Money', 'sort_order' => 3],
            ['code' => 'HALOPESA', 'name' => 'HaloPesa'],
        ]);

        $this->fill(MaritalStatusOption::class, [
            ['code' => 'SINGLE', 'name' => 'Single', 'sort_order' => 1],
            ['code' => 'MARRIED', 'name' => 'Married', 'sort_order' => 2],
            ['code' => 'DIVORCED', 'name' => 'Divorced', 'sort_order' => 3],
            ['code' => 'WIDOWED', 'name' => 'Widowed', 'sort_order' => 4],
        ]);
    }

    /**
     * @param class-string<\App\Models\MasterData\MasterDataModel> $model
     * @param list<array<string, mixed>> $rows
     */
    private function fill(string $model, array $rows): void
    {
        foreach ($rows as $row) {
            $model::query()->updateOrCreate(['code' => $row['code']], $row);
        }
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Order matters: permissions must exist before roles can be granted them, and
 * roles before users can reference one.
 *
 * Note the deliberate absence of the `WithoutModelEvents` trait that Laravel's
 * stub ships with. User::booted() keeps Spatie's role pivot in sync with the
 * authoritative `users.role_id` on save — muting model events would produce
 * seeded users whose permissions resolve to nothing, and the failure would
 * only surface later as a puzzling 403.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,

            // Geography before organization (branches reference regions), and
            // both before users (users reference branches, zones and regions).
            GeographySeeder::class,
            OrganizationSeeder::class,

            UserSeeder::class,

            // Categories before customers (a customer names one), and users
            // before both (customers record who registered them).
            CustomerCategorySeeder::class,
            CustomerSeeder::class,

            // The eighteen customers the legacy screens actually name. After
            // CustomerSeeder because it shares the customer-number sequence.
            LegacyCustomerSeeder::class,

            // Products reference categories (the §2.3 eligibility pivot);
            // loans reference both, plus the customers and users above.
            LoanProductSeeder::class,

            // Fees before loans: a loan snapshots its product's fee at
            // application, so a loan seeded first would carry none.
            LoanFeeSeeder::class,

            LoanSeeder::class,

            // The chart must exist before anything can post to it; the
            // activity seeder then drives real money through the same engine
            // the API uses.
            ChartOfAccountSeeder::class,
            LedgerActivitySeeder::class,

            // Staff after users (a profile mirrors its user's branch and
            // zone), and payroll last of all: a commission pool is computed
            // from branch profit, which is not known until the loan book has
            // earned some.
            StaffSeeder::class,
            PayrollSeeder::class,

            // The seven headquarters accounts, with the balances the legacy
            // system shows. Independent of everything above — it is a
            // standalone transcription and depends on no other seed.
            HqAccountSeeder::class,

            /*
             * Overdue installments, their penalties, and one collected. After
             * LedgerActivitySeeder because it ages loans that seeder has
             * already disbursed, and because collecting a penalty posts to the
             * ledger through the ordinary repayment path.
             */
            PenaltySeeder::class,

            // Expense registers and a queue of requests. After the chart,
            // because approving one posts through LedgerService; after
            // OrganizationSeeder, because a request names the branch bearing
            // the cost.
            ExpenseSeeder::class,
        ]);
    }
}

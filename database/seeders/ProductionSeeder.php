<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The only seeder a production installation runs.
 *
 *     php artisan db:seed --class=ProductionSeeder --force
 *
 * WHAT IT CREATES, AND WHY EACH ONE IS NOT BUSINESS DATA.
 *
 * Permissions and roles are the application's own vocabulary — every policy,
 * every route and every test names them, and a database without them is one
 * where nobody can do anything. They are code that happens to live in rows.
 *
 * The System account is a platform rule, not a user: it is what the ledger
 * attributes automated postings to, it cannot log in, and its absence makes
 * every scheduled job fail.
 *
 * The chart of accounts is double-entry structure. The ledger posts to
 * accounts by code; an empty chart means no transaction can be recorded at
 * all.
 *
 * The default account-type requirement row is the fallback
 * AccountTypeRequirementResolver returns when an account type has no profile
 * of its own. Without it, registration 503s.
 *
 * WHAT IT DELIBERATELY DOES NOT CREATE.
 *
 * Regions, districts, wards, streets, banks, mobile money providers,
 * occupations, customer types, account types, loan types, customer categories,
 * sectors, cadres, employers, ID types, contract types, document types, loan
 * products, fees, penalties, approval stages — none of it. Every one of those
 * is an institutional decision, and shipping a guess would mean an
 * administrator inherits somebody else's assumptions and has to notice before
 * correcting them.
 *
 * A fresh install therefore starts with those tables EMPTY. Registration and
 * lending both say so, and name the Administration screen that fills them.
 * `DatabaseSeeder` is the development and demonstration seeder; it is not this.
 */
final class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // The application's own vocabulary.
            PermissionSeeder::class,
            RoleSeeder::class,

            // A platform rule, not a person.
            SystemUserSeeder::class,

            // Double-entry structure. Nothing can post without it.
            ChartOfAccountSeeder::class,

            /*
             * The fallback requirement profile. Creates the default row that
             * every account type without its own profile falls back to; it
             * requires almost nothing, because a form that cannot learn its
             * rules must not invent strict ones.
             */
            AccountTypeRequirementSeeder::class,
        ]);
    }
}

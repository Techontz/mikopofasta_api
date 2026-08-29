<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * A fresh production installation starts with no institutional data.
 *
 * THE RULE THIS PROTECTS. This application ships structure, API, admin screens
 * and empty states. Which banks an institution works with, which documents it
 * accepts, where it lends, what it lends and who approves it are the
 * institution's decisions, configured at Administration → Master Data before
 * the first customer is registered.
 *
 * `RefreshDatabase` has already run every migration, so what this asserts is
 * that the MIGRATIONS create no business rows. `ProductionSeeder` is then run
 * to prove it adds only what the application cannot start without.
 *
 * If this fails, something has started shipping an institution's data again.
 */
it('creates no business or reference data from migrations alone', function (): void {
    $business = [
        'regions', 'districts', 'wards', 'streets',
        'sectors', 'sector_categories', 'employers',
        'id_types', 'contract_types', 'document_types',
        'customer_categories', 'customer_types', 'account_types', 'loan_types',
        'work_types', 'employment_types', 'occupations',
        'banks', 'mobile_money_providers', 'marital_statuses',
        'loan_products', 'loan_fees', 'repayment_schedules', 'loan_approval_stages',
        'category_product_eligibility', 'customers', 'loans',
        /* Institution-specific too, and easy to miss: which banks the
           institution keeps its float with, and its branch network. */
        'bank_accounts', 'branches', 'zones',
    ];

    $populated = [];

    foreach ($business as $table) {
        $count = DB::table($table)->count();

        if ($count > 0) {
            $populated[] = "{$table} ({$count})";
        }
    }

    expect($populated)->toBe([]);
});

it('runs ProductionSeeder without creating any business data', function (): void {
    $this->seed(Database\Seeders\ProductionSeeder::class);

    expect(DB::table('banks')->count())->toBe(0)
        ->and(DB::table('customer_categories')->count())->toBe(0)
        ->and(DB::table('regions')->count())->toBe(0)
        ->and(DB::table('sectors')->count())->toBe(0)
        ->and(DB::table('loan_products')->count())->toBe(0)
        /* The one this audit caught: ChartOfAccountSeeder used to create two
           named bank accounts under invented numbers. */
        ->and(DB::table('bank_accounts')->count())->toBe(0)
        ->and(DB::table('branches')->count())->toBe(0)
        ->and(DB::table('account_types')->count())->toBe(0);

    /* And it DOES create what the application cannot start without. */
    expect(DB::table('permissions')->count())->toBeGreaterThan(0)
        ->and(DB::table('roles')->count())->toBeGreaterThan(0)
        ->and(DB::table('chart_of_accounts')->count())->toBeGreaterThan(0)
        /* The fallback requirement profile — registration 503s without it. */
        ->and(DB::table('account_type_requirements')->whereNull('account_type_id')->count())->toBe(1);
});

/* The switch that could take an existing book's eligibility away must ship
   off, in the shipped data as well as in the column default. */
it('ships document enforcement off', function (): void {
    $this->seed(Database\Seeders\ProductionSeeder::class);

    foreach (DB::table('account_type_requirements')->get() as $profile) {
        expect((bool) $profile->requires_category_documents)->toBeFalse()
            ->and($profile->category_documents_enforced_from)->toBeNull();
    }
});

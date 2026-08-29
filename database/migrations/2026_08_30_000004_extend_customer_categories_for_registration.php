<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The category says which of the new registration blocks it needs, and the two
 * categories the documents list but the seed never had.
 *
 * WHY FLAGS ON THE CATEGORY RATHER THAN A SECOND SCHEMA. Sector, contract and
 * salary are now real columns on `customers` — typed, foreign-keyed, queryable
 * by the eligibility engine. Re-declaring them inside `dynamic_form_schema`
 * would store the same fact twice in two shapes and give the system a second,
 * competing way to describe a customer's employment. So the schema keeps doing
 * what it is good at (the questions unique to a category) and three booleans
 * say which of the FIRST-CLASS blocks this category asks for. One table, one
 * source of truth, no new mechanism.
 *
 * `customer_categories.sector` — the existing `employment|business|other` enum
 * — was the obvious candidate and is deliberately not used for this. It is a
 * presentation grouping that decides a heading and an icon; making it also
 * decide which fields are required would give one column two unrelated jobs
 * and make either impossible to change alone.
 *
 * THE TWO MISSING CATEGORIES. `CUSTOMER REGISTRATION OVERVIEW.docx` lists
 * seven categories; five were seeded. Wanachuo and Retired are added here with
 * their own questions and document lists. Nothing is renamed: the five
 * existing codes are referenced by `category_product_eligibility` rows and by
 * every customer already filed under them.
 *
 * DOCUMENT LISTS ARE UPDATED, NOT ENFORCED. Changing `required_documents`
 * changes what the profile REPORTS as missing. It changes nothing about who
 * may borrow, because KycEvaluator still treats the category list as advisory
 * — see the 2026_08_30_000005 migration for the switch that would change that,
 * which ships OFF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_categories', function (Blueprint $table): void {
            $table->boolean('requires_sector')->default(false)->after('sector');
            $table->boolean('requires_contract')->default(false)->after('requires_sector');
            $table->boolean('requires_salary')->default(false)->after('requires_contract');
        });

    }

    public function down(): void
    {
        Schema::table('customer_categories', function (Blueprint $table): void {
            $table->dropColumn(['requires_sector', 'requires_contract', 'requires_salary']);
        });
    }
};

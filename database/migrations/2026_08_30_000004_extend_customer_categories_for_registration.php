<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        /*
         * Employment categories ask for all three. Business categories ask for
         * none of them — a Boda Boda rider has no employer, no contract and no
         * payslip, and asking would produce blank fields on every application.
         */
        DB::table('customer_categories')
            ->whereIn('code', ['PUBLIC_SERVANT', 'PRIVATE_SECTOR'])
            ->update([
                'requires_sector' => true,
                'requires_contract' => true,
                'requires_salary' => true,
            ]);

        $now = now();

        /* The four document types the new lists name and the seed did not
           have. `employer_letter` is kept and NOT reused as the confirmation
           letter: a letter of introduction and a letter confirming employment
           are different documents, and a branch asked for both would have no
           way to file the second. */
        DB::table('document_types')->insert(array_map(
            static fn (array $r): array => [
                'code' => $r[0],
                'name' => $r[1],
                'description' => $r[2],
                'sort_order' => $r[3],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                ['confirmation_letter', 'Confirmation Letter', 'Employer letter confirming the customer holds the post.', 110],
                ['bank_card', 'Bank Card', 'Copy of the salary account card.', 120],
                ['employee_id', 'Employee ID', 'Employer-issued identity card.', 130],
                ['employment_contract', 'Employment Contract', 'Signed contract of employment.', 140],
                ['student_id', 'Student ID', 'Institution-issued student identity card.', 150],
                ['pension_statement', 'Pension Statement', 'Statement evidencing pension income.', 160],
            ],
        ));

        /* The documented lists, replacing the two-item placeholders. */
        DB::table('customer_categories')->where('code', 'PUBLIC_SERVANT')->update([
            'required_documents' => json_encode([
                'confirmation_letter', 'salary_slip', 'bank_card', 'employee_id', 'national_id',
            ]),
        ]);

        DB::table('customer_categories')->where('code', 'PRIVATE_SECTOR')->update([
            'required_documents' => json_encode([
                'employment_contract', 'bank_statement', 'national_id', 'driving_license',
            ]),
        ]);

        DB::table('customer_categories')->insert([
            [
                'name' => 'Wanachuo',
                'code' => 'STUDENT',
                'risk_tier' => 'high',
                'sector' => 'other',
                'requires_sector' => false,
                'requires_contract' => false,
                'requires_salary' => false,
                'required_documents' => json_encode(['student_id', 'national_id']),
                'dynamic_form_schema' => json_encode([
                    ['key' => 'institution_name', 'type' => 'text', 'label' => 'Institution', 'required' => true],
                    ['key' => 'course_of_study', 'type' => 'text', 'label' => 'Course of Study', 'required' => true],
                    ['key' => 'expected_completion_year', 'type' => 'number', 'label' => 'Expected Completion Year', 'required' => true],
                    ['key' => 'sponsor_name', 'type' => 'text', 'label' => 'Sponsor or Guardian', 'required' => false],
                ]),
                'requires_extra_approval' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Retired',
                'code' => 'RETIRED',
                'risk_tier' => 'medium',
                'sector' => 'other',
                'requires_sector' => false,
                'requires_contract' => false,
                'requires_salary' => false,
                'required_documents' => json_encode(['pension_statement', 'national_id']),
                'dynamic_form_schema' => json_encode([
                    ['key' => 'former_employer', 'type' => 'text', 'label' => 'Former Employer', 'required' => true],
                    ['key' => 'monthly_pension', 'type' => 'number', 'label' => 'Monthly Pension (TZS)', 'required' => true],
                    ['key' => 'pension_number', 'type' => 'text', 'label' => 'Pension Number', 'required' => false],
                ]),
                'requires_extra_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        /* The two new categories go only if nobody was filed under them —
           deleting a category a customer points at would orphan the record. */
        foreach (['STUDENT', 'RETIRED'] as $code) {
            $id = DB::table('customer_categories')->where('code', $code)->value('id');

            if ($id !== null && DB::table('customers')->where('customer_category_id', $id)->doesntExist()) {
                DB::table('customer_categories')->where('id', $id)->delete();
            }
        }

        DB::table('customer_categories')->where('code', 'PUBLIC_SERVANT')
            ->update(['required_documents' => json_encode(['salary_slip', 'employer_letter'])]);
        DB::table('customer_categories')->where('code', 'PRIVATE_SECTOR')
            ->update(['required_documents' => json_encode(['salary_slip', 'employer_letter'])]);

        DB::table('document_types')->whereIn('code', [
            'confirmation_letter', 'bank_card', 'employee_id',
            'employment_contract', 'student_id', 'pension_statement',
        ])->delete();

        Schema::table('customer_categories', function (Blueprint $table): void {
            $table->dropColumn(['requires_sector', 'requires_contract', 'requires_salary']);
        });
    }
};

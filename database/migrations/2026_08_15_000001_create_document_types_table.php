<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tenth admin-managed lookup list — KYC document types.
 *
 * The upload panel asked staff to *type* the document type into a free-text
 * box, with "e.g. salary_slip" as the only guidance. The result is exactly
 * what a free-text key always produces: this database already holds a customer
 * document filed under `HJK`. It is attached to a real customer, it satisfies
 * nothing on any category's required list, and nobody can tell what it is.
 *
 * A category's `required_documents` is a list of these codes. When the code an
 * officer types has to match one of them character for character and nothing
 * checks that it does, the requirement is unenforceable by construction —
 * `salary_slip`, `salary slip` and `Salary_Slip` are three different documents
 * to the checklist and one document to the person uploading it.
 *
 * Same shape as the other nine lists (see the 2026_08_02 migration), so it
 * rides on the same controller, policy, resource and admin screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });

        /*
         * Seeded with the codes the shipped customer categories already
         * require. These are not invented: every one is read out of
         * `customer_categories.required_documents`, so the checklist has
         * something to match on from the first upload rather than after
         * somebody remembers to configure the list.
         */
        $now = now();
        $rows = [
            ['driving_license', 'Driving Licence', 'Required for Boda Boda customers.', 10],
            ['motorcycle_registration', 'Motorcycle Registration', 'Ownership card for the financed motorcycle.', 20],
            ['business_license', 'Business Licence', 'Trading licence for an SME customer.', 30],
            ['financial_statement', 'Financial Statement', 'Accounts for a medium SME.', 40],
            ['salary_slip', 'Salary Slip', 'Most recent payslip.', 50],
            ['employer_letter', 'Employer Letter', 'Letter of introduction from the employer.', 60],
            ['national_id', 'National ID', 'A copy of the customer’s national identity card.', 70],
            ['passport_photo', 'Passport Photo', 'A printed photograph, where one is held on paper.', 80],
            ['bank_statement', 'Bank Statement', 'Recent statement supporting declared income.', 90],
            ['contract', 'Contract', 'Signed loan or guarantee contract.', 100],
        ];

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
            $rows,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};

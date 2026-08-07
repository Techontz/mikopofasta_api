<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Loan Product becomes the single source of truth for pricing.
 *
 * Three changes, all in service of one rule: **no hardcoded values.**
 *
 * ## 1. `interest_formulas.code` stops being an ENUM
 *
 * It was `ENUM('SIMPLE','FLAT','REDUCING')`, so adding a formula meant a
 * migration and a deploy — the opposite of "administrators must manage them".
 * It becomes a plain string, and the authority moves to
 * InterestStrategyRegistry: a code is valid when a strategy implements it, and
 * the product validator refuses to save a product naming one that does not.
 *
 * The database can no longer reject an unimplemented code, and that is
 * deliberate. A CHECK constraint would have to list the strategies, which puts
 * us back where we started.
 *
 * ## 2. `penalty_types` becomes a master table
 *
 * `loan_products.penalty_type` was an ENUM too. It becomes a foreign key to an
 * administrator-managed table. The existing enum column is kept in step during
 * the transition so nothing reading it breaks; see the note on the column.
 *
 * ## 3. The product carries its full configuration
 *
 * Description, grace period, processing fee, insurance fee and the commission
 * rate the product earns. These were either absent or scattered — the fees in
 * particular lived only in `loan_fees`, which is a per-product charge register
 * rather than part of the product's own terms.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * MySQL cannot widen an ENUM to VARCHAR in place without rewriting the
         * column definition, so the change is explicit. `code` keeps its unique
         * index — two formulas with the same code would make the registry
         * lookup ambiguous.
         */
        DB::statement('ALTER TABLE `interest_formulas` MODIFY COLUMN `code` VARCHAR(40) NOT NULL');

        Schema::create('penalty_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 60);
            $table->string('code', 40)->unique();
            $table->string('description', 255)->nullable();

            /*
             * How `loan_products.penalty_rate` is read for this type.
             *
             * `percentage` — a rate applied to the overdue amount.
             * `fixed`      — a flat amount in shillings.
             *
             * Kept as data rather than inferred from the code, because a
             * future penalty type ("2% per day, capped") needs the unit stated
             * rather than guessed from its name.
             */
            $table->enum('rate_unit', ['percentage', 'fixed'])->default('percentage');

            /** Whether the charge repeats for every day past due. */
            $table->boolean('accrues_daily')->default(false);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('loan_products', function (Blueprint $table): void {
            // Configuration the client's specification requires and the product
            // did not carry.
            $table->string('description', 500)->nullable()->after('code');

            /*
             * Days before the first installment falls due, beyond the first
             * period. Zero is the ordinary case.
             *
             * It moves the due dates and nothing else — a product that forgave
             * interest during grace would be a different formula, and encoding
             * that here would put calculation logic back on the product row.
             */
            $table->unsignedInteger('grace_period_days')->default(0)->after('max_tenure_days');

            /*
             * Origination charges, as a percentage of principal.
             *
             * Separate columns rather than one "fees" figure because they are
             * different obligations: a processing fee is the lender's income, an
             * insurance premium is collected on behalf of an insurer. They post
             * differently and they are reported separately.
             */
            $table->decimal('processing_fee_rate', 6, 3)->default(0)->after('grace_period_days');
            $table->decimal('insurance_fee_rate', 6, 3)->default(0)->after('processing_fee_rate');

            /*
             * The commission this product contributes, as a percentage.
             *
             * Nullable: null means "use the company-wide rate", which is the
             * behaviour every product has today. A value overrides it for this
             * product only. Decision Register D7 requires commission to come
             * from configured rules rather than a constant.
             */
            $table->decimal('commission_rate', 6, 3)->nullable()->after('insurance_fee_rate');

            /*
             * Higher commission on money recovered after default — D7 again,
             * from the client's "mikopo iliyodefault ikirudishwa kutakuwa na
             * commission kubwa zaidi".
             */
            $table->decimal('recovery_commission_rate', 6, 3)->nullable()->after('commission_rate');

            $table->foreignId('penalty_type_id')->nullable()->after('penalty_type')
                ->constrained('penalty_types')->nullOnDelete();
        });

        /*
         * Seed the penalty types from the enum that is being replaced, so every
         * existing product can be pointed at a row without an administrator
         * having to create one first.
         */
        $now = now();

        DB::table('penalty_types')->insert([
            [
                'name' => 'Percentage of overdue',
                'code' => 'percentage_of_overdue',
                'description' => 'A percentage of the amount past due, charged once per overdue installment.',
                'rate_unit' => 'percentage',
                'accrues_daily' => false,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Flat fee',
                'code' => 'flat_fee',
                'description' => 'A fixed amount in shillings, charged once per overdue installment.',
                'rate_unit' => 'fixed',
                'accrues_daily' => false,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Percentage per day',
                'code' => 'percentage_per_day',
                'description' => 'A percentage of the amount past due, charged for every day it remains overdue.',
                'rate_unit' => 'percentage',
                'accrues_daily' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Point every existing product at the row matching its enum value.
        DB::statement(<<<'SQL'
            UPDATE loan_products lp
            JOIN penalty_types pt ON pt.code = lp.penalty_type
            SET lp.penalty_type_id = pt.id
        SQL);
    }

    public function down(): void
    {
        Schema::table('loan_products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('penalty_type_id');
            $table->dropColumn([
                'description',
                'grace_period_days',
                'processing_fee_rate',
                'insurance_fee_rate',
                'commission_rate',
                'recovery_commission_rate',
            ]);
        });

        Schema::dropIfExists('penalty_types');

        /*
         * Clear codes the original ENUM cannot hold, before narrowing back to it.
         *
         * up() widened this column to VARCHAR precisely so formulas could be
         * added without a deploy, and LoanProductSeeder then adds REDUCING_EMI.
         * Narrowing the column with that row present truncates it — MySQL
         * raises 1265 and the rollback dies here. `migrate:rollback` on any
         * seeded database therefore failed at this migration.
         *
         * Deleting them is what restoring the previous state means: they are
         * values the old schema was incapable of storing, so they cannot have
         * existed before up() ran.
         */
        DB::table('interest_formulas')
            ->whereNotIn('code', ['SIMPLE', 'FLAT', 'REDUCING'])
            ->delete();

        DB::statement(
            "ALTER TABLE `interest_formulas` MODIFY COLUMN `code` ENUM('SIMPLE','FLAT','REDUCING') NOT NULL",
        );
    }
};

<?php

declare(strict_types=1);

use App\Domain\Loans\Enums\InterestFormulaCode;
use App\Domain\Loans\Enums\PenaltyType;
use App\Enums\ActiveStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backend spec §2.3 — the loan configuration layer.
     *
     * The locked-in three-entity split: CustomerCategory (Phase 4) drives
     * KYC/risk/approval, LoanProduct drives interest/limits/tenure/mandate,
     * and RepaymentSchedule drives cadence. Two pivots connect them —
     * which schedules a product allows, and which products a category may
     * apply for.
     *
     * §6 is emphatic that nothing here is hardcoded: every commercial term of
     * a loan lives on `loan_products`, and the loan engine reads this row for
     * every decision it makes. A Super Admin changing a product's terms takes
     * effect immediately for new applications with no code deploy.
     */
    public function up(): void
    {
        Schema::create('interest_formulas', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 60)->unique();
            $table->enum('code', InterestFormulaCode::values())->unique();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('repayment_schedules', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('code', 20)->unique();

            // 1 / 7 / 30, or a group-cycle length. Drives installment count.
            $table->unsignedInteger('frequency_days');

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('loan_products', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 40)->unique();

            $table->foreignId('interest_formula_id')->constrained('interest_formulas')
                ->restrictOnDelete()->cascadeOnUpdate();

            // DECIMAL(6,3) — read through App\Support\Percentage, never a float.
            $table->decimal('interest_rate', 6, 3);

            $table->decimal('min_amount', 18, 2);
            $table->decimal('max_amount', 18, 2);
            $table->unsignedInteger('min_tenure_days');
            $table->unsignedInteger('max_tenure_days');

            $table->enum('penalty_type', PenaltyType::values())
                ->default(PenaltyType::PercentageOfOverdue->value);

            /*
             * OSC-2 — a specification conflict, resolved here by widening.
             *
             * §2.3 types this DECIMAL(6,3) *and* says its "meaning depends on
             * penalty_type (% or flat amount)". Those two statements cannot
             * both hold: DECIMAL(6,3) caps at 999.999, while the frontend's
             * own seed gives the Salary Advance product a flat_fee
             * penaltyRate of 10,000 TZS — which does not fit.
             *
             * Widening to DECIMAL(18,3) keeps the spec's single-column design
             * (adding a second column would redesign the entity) and stores
             * both readings losslessly: a percentage needs the 3 decimals, a
             * flat TZS amount needs the range. Nothing else changes — the unit
             * is still decided by penalty_type, exactly as §2.3 says.
             *
             * See "Open Specification Conflicts" in the README.
             */
            $table->decimal('penalty_rate', 18, 3)->default(0);

            $table->unsignedInteger('penalty_grace_days')->default(0);
            $table->decimal('penalty_cap_amount', 18, 2)->nullable();

            $table->boolean('requires_mandate')->default(false);
            $table->enum('status', ActiveStatus::values())->default(ActiveStatus::Active->value);

            $table->foreignId('created_by')->nullable()->constrained('users')
                ->nullOnDelete()->cascadeOnUpdate();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('interest_formula_id');
        });

        /*
         * Which cadences a product allows. A loan's repayment_schedule_id must
         * appear here for its product, checked at application time (§6) — an
         * application requesting an unsupported schedule is rejected with
         * SCHEDULE_NOT_SUPPORTED_BY_PRODUCT, never silently coerced.
         */
        Schema::create('loan_product_repayment_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_product_id')->constrained('loan_products')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('repayment_schedule_id')->constrained('repayment_schedules')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->timestamps();

            // Named explicitly: the conventional name would be 76 characters,
            // over MySQL's 64-character identifier limit.
            $table->unique(['loan_product_id', 'repayment_schedule_id'], 'lprs_product_schedule_unique');
        });

        /*
         * The category → product rule engine (§2.3). A row must exist for
         * (customer's category, product) or the application is refused.
         * `max_amount_override` caps the product's own maximum for this
         * category when present.
         */
        Schema::create('category_product_eligibility', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_category_id')->constrained('customer_categories')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('loan_product_id')->constrained('loan_products')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->decimal('max_amount_override', 18, 2)->nullable();
            $table->boolean('requires_extra_approval')->default(false);
            $table->timestamps();

            $table->unique(['customer_category_id', 'loan_product_id'], 'cpe_category_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_product_eligibility');
        Schema::dropIfExists('loan_product_repayment_schedules');
        Schema::dropIfExists('loan_products');
        Schema::dropIfExists('repayment_schedules');
        Schema::dropIfExists('interest_formulas');
    }
};

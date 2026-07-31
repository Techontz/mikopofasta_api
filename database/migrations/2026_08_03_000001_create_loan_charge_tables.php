<?php

declare(strict_types=1);

use App\Domain\Loans\Enums\ChargeValueType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loan Charges & Reserve — Settings → Loan Fee / Penalty / Reserve Setting.
 *
 * See docs/modules/loan-charges.md. Nothing here alters an existing table:
 * loan products keep their own penalty columns and remain the value the loan
 * engine reads, so no live loan is re-priced by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * One fee configuration per loan product — the legacy screen lists one
         * row per loan category, so the relationship is 1:1 and the unique
         * index enforces it rather than leaving duplicates possible.
         */
        Schema::create('loan_fees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_product_id')
                ->unique()
                ->constrained('loan_products')
                ->cascadeOnDelete();

            $table->enum('fee_type', ChargeValueType::values())
                ->default(ChargeValueType::MoneyValue->value);

            // TZS when fee_type is money_value, a percentage when it is not.
            // Two decimals covers both: the legacy fees are whole shillings and
            // its percentages are whole numbers.
            $table->decimal('fee_amount', 18, 2)->default(0);

            // A flat premium in the legacy data, never a percentage.
            $table->decimal('insurance_amount', 18, 2)->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        /*
         * The organisation-wide penalty default. Deliberately NOT read by the
         * overdue job — see the boundary note in the module doc.
         */
        Schema::create('penalty_settings', function (Blueprint $table): void {
            $table->id();
            $table->enum('calculation_type', ChargeValueType::values())
                ->default(ChargeValueType::PercentageValue->value);

            // Three decimals to match loan_products.penalty_rate, so a value
            // copied from here into a new product survives the round trip.
            $table->decimal('amount', 18, 3)->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        /*
         * Singleton, the same shape company_profiles uses: the row is created
         * on first read and updated thereafter, never inserted twice.
         */
        Schema::create('reserve_settings', function (Blueprint $table): void {
            $table->id();
            $table->decimal('percentage', 6, 3)->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserve_settings');
        Schema::dropIfExists('penalty_settings');
        Schema::dropIfExists('loan_fees');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer types hold no loan configuration: the loan limits (min/max_loan_amount) and the allowed loan products pivot are
     * dropped. Limits now come from the loan category (amount_from / amount_to) and the products from the customer type's
     * main loan category. down() re-creates the columns and the pivot empty.
     */
    public function up(): void
    {
        Schema::dropIfExists('customer_category_loan_category');

        Schema::table('customer_categories', function (Blueprint $table) {
            $table->dropColumn(['min_loan_amount', 'max_loan_amount']);
        });
    }

    public function down(): void
    {
        Schema::table('customer_categories', function (Blueprint $table) {
            $table->decimal('min_loan_amount', 15, 2)->default(0)->after('risk_level');
            $table->decimal('max_loan_amount', 15, 2)->default(0)->after('min_loan_amount');
        });

        Schema::create('customer_category_loan_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_category_id')->constrained()->cascadeOnDelete();
            $table->unique(['customer_category_id', 'loan_category_id'], 'category_product_unique');
        });
    }
};

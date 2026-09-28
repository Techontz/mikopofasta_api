<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer categories ("Category = Rule Engine", Documents: CUSTOMER REGISTRATION OVERVIEW).
     * Each category controls the loan products allowed, loan limits, required documents,
     * risk level (approval logic) and the dynamic registration form.
     */
    public function up(): void
    {
        Schema::create('customer_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->string('icon', 16)->nullable();
            $table->string('section_title')->nullable();
            $table->string('risk_level')->default('medium');
            $table->decimal('min_loan_amount', 15, 2)->default(0);
            $table->decimal('max_loan_amount', 15, 2)->default(0);
            $table->json('required_documents');
            $table->json('form_schema');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'key']);
        });

        Schema::create('customer_category_loan_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_category_id')->constrained()->cascadeOnDelete();
            $table->unique(['customer_category_id', 'loan_category_id'], 'category_product_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_category_loan_category');
        Schema::dropIfExists('customer_categories');
    }
};

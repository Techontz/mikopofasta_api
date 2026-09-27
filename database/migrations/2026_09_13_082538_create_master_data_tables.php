<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('interest_formulas', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_enabled')->default(false);
        });

        Schema::create('main_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_enabled')->default(true);
        });

        Schema::create('customer_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('main_category_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_enabled')->default(true);
        });

        Schema::create('loan_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('main_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->decimal('amount_from', 15, 2);
            $table->decimal('amount_to', 15, 2);
            $table->decimal('interest_rate', 6, 2);
            $table->string('formula')->default('SIMPLE');
            $table->string('duration')->default('weekly');
            $table->unsignedInteger('repayment_from')->default(1);
            $table->unsignedInteger('repayment_to')->default(1);
            $table->boolean('fee_deduct')->default(true);
            $table->boolean('has_penalty')->default(true);
            $table->string('approve_level')->default('hq');
            $table->decimal('topup_percent', 6, 2)->default(0);
            $table->decimal('take_home_percent', 6, 2)->default(0);
            $table->string('fee_type')->default('money');
            $table->decimal('fee_value', 15, 2)->default(0);
            $table->decimal('insurance', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('branch_loan_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_category_id')->constrained()->cascadeOnDelete();
            $table->unique(['branch_id', 'loan_category_id']);
        });

        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('expense_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('scope');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('payment_modes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_modes');
        Schema::dropIfExists('expense_types');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('groups');
        Schema::dropIfExists('branch_loan_category');
        Schema::dropIfExists('loan_categories');
        Schema::dropIfExists('customer_types');
        Schema::dropIfExists('main_categories');
        Schema::dropIfExists('interest_formulas');
    }
};

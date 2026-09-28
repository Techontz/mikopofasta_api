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
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_category_id')->constrained();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('loan_number')->unique();
            $table->decimal('amount_applied', 15, 2);
            $table->decimal('amount_approved', 15, 2)->default(0);
            $table->string('duration');
            $table->unsignedInteger('sessions');
            $table->decimal('instalment', 15, 2)->default(0);
            $table->string('formula');
            $table->boolean('fee_deduct')->default(true);
            $table->string('reason');
            $table->decimal('interest_rate', 6, 2);
            $table->decimal('interest_amount', 15, 2)->default(0);
            $table->decimal('total_payable', 15, 2)->default(0);
            $table->decimal('loan_fee', 15, 2)->default(0);
            $table->decimal('insurance', 15, 2)->default(0);
            $table->decimal('restoration', 15, 2)->default(0);
            $table->string('status')->default('pending');
            $table->boolean('is_special')->default(false);
            $table->string('agreement_file')->nullable();
            $table->string('collateral_attachment')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->date('withdrawn_at')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });

        Schema::create('collaterals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->string('location');
            $table->decimal('value', 15, 2);
            $table->timestamps();
        });

        Schema::create('loan_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->date('due_date');
            $table->decimal('amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('loan_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('description');
            $table->string('method')->default('CASH');
            $table->decimal('amount', 15, 2);
            $table->decimal('principal', 15, 2)->default(0);
            $table->decimal('interest', 15, 2)->default(0);
            $table->decimal('reserve', 15, 2)->default(0);
            $table->date('transaction_date');
            $table->timestamps();
        });

        Schema::create('penalties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->boolean('is_waived')->default(false);
            $table->date('penalty_date');
            $table->timestamps();
        });

        Schema::create('penalty_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('penalty_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('paid_on');
            $table->timestamps();
        });

        Schema::create('write_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('recovered_amount', 15, 2)->default(0);
            $table->string('description')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->date('written_off_on');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('write_offs');
        Schema::dropIfExists('penalty_payments');
        Schema::dropIfExists('penalties');
        Schema::dropIfExists('loan_transactions');
        Schema::dropIfExists('loan_schedules');
        Schema::dropIfExists('collaterals');
        Schema::dropIfExists('loans');
    }
};

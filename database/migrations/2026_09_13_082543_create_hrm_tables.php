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
        Schema::create('employee_salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('salary', 15, 2);
            $table->string('account_name');
            $table->string('account_number');
            $table->decimal('fee', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('staff_allowances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('staff_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->unsignedInteger('instalments');
            $table->decimal('instalment_amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('staff_loan_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('amount_from', 15, 2);
            $table->decimal('amount_to', 15, 2);
            $table->decimal('interest_rate', 6, 2);
            $table->string('duration');
            $table->unsignedInteger('repayment_from');
            $table->unsignedInteger('repayment_to');
            $table->decimal('fee', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('staff_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_loan_category_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount_applied', 15, 2);
            $table->decimal('amount_approved', 15, 2)->default(0);
            $table->string('duration');
            $table->unsignedInteger('sessions');
            $table->decimal('total_payable', 15, 2)->default(0);
            $table->decimal('restoration', 15, 2)->default(0);
            $table->decimal('fee', 15, 2)->default(0);
            $table->text('reason');
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('staff_loan_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_loan_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('paid_on');
            $table->timestamps();
        });

        Schema::create('staff_salary_advance_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('amount_from', 15, 2);
            $table->decimal('amount_to', 15, 2);
            $table->decimal('fee', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('staff_salary_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_salary_advance_category_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('fee', 15, 2)->default(0);
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->text('remarks');
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('salary_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('salary', 15, 2);
            $table->decimal('salary_advance', 15, 2)->default(0);
            $table->decimal('allowance', 15, 2)->default(0);
            $table->decimal('deduction', 15, 2)->default(0);
            $table->decimal('loan_restoration', 15, 2)->default(0);
            $table->decimal('take_home', 15, 2);
            $table->string('phone')->nullable();
            $table->string('account_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('paid_from_account');
            $table->date('paid_on');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['salary_payments', 'leaves', 'staff_salary_advances', 'staff_salary_advance_categories', 'staff_loan_payments', 'staff_loans', 'staff_loan_categories', 'staff_deductions', 'staff_allowances', 'employee_salaries'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

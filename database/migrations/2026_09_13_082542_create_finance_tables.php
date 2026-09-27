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
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('account');
            $table->decimal('amount', 15, 2);
            $table->string('description');
            $table->nullableMorphs('reference');
            $table->date('entry_date');
            $table->timestamps();
            $table->index(['company_id', 'account', 'branch_id']);
        });

        Schema::create('share_holders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('mobile');
            $table->string('email');
            $table->string('gender')->nullable();
            $table->date('date_of_birth');
            $table->timestamps();
        });

        Schema::create('capitals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('share_holder_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('pay_method');
            $table->string('receipt_number')->nullable();
            $table->string('cheque_number')->nullable();
            $table->timestamps();
        });

        Schema::create('float_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->foreignId('from_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('to_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('from_account')->nullable();
            $table->string('to_account')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('status')->default('pending');
            $table->date('transfer_date');
            $table->timestamps();
        });

        Schema::create('bank_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('branch_account')->nullable();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hq_account')->nullable();
            $table->decimal('amount', 15, 2);
            $table->decimal('charge', 15, 2)->default(0);
            $table->string('status')->default('approved');
            $table->date('transfer_date');
            $table->timestamps();
        });

        Schema::create('expense_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('scope');
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->text('description')->nullable();
            $table->text('comment')->nullable();
            $table->string('status')->default('pending');
            $table->date('request_date');
            $table->timestamps();
        });

        Schema::create('hq_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('from_account');
            $table->string('to_account');
            $table->decimal('amount', 15, 2);
            $table->decimal('charge', 15, 2)->default(0);
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('salary_advance_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('interest_rate', 6, 2);
            $table->decimal('amount_from', 15, 2);
            $table->decimal('amount_to', 15, 2);
            $table->decimal('fee', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('salary_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_advance_category_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('interest_rate', 6, 2);
            $table->decimal('total_payable', 15, 2);
            $table->decimal('fee', 15, 2)->default(0);
            $table->string('status')->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('salary_advance_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_advance_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('paid_on');
            $table->timestamps();
        });

        Schema::create('agent_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('agent')->nullable();
            $table->decimal('amount', 15, 2);
            $table->decimal('loan_amount', 15, 2)->default(0);
            $table->date('transaction_date');
            $table->time('transaction_time')->nullable();
            $table->timestamps();
        });

        Schema::create('savings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('description');
            $table->decimal('amount', 15, 2);
            $table->date('transaction_date');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['savings', 'agent_transactions', 'salary_advance_payments', 'salary_advances', 'salary_advance_categories', 'hq_transactions', 'expense_requests', 'bank_transfers', 'float_transfers', 'capitals', 'share_holders', 'ledger_entries'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

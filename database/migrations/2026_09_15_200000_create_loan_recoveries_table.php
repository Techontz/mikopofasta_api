<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recoveries after write-off (rule 8): money received later on a written-off loan is a new transaction recorded directly
     * into INTEREST INCOME. Kept apart from loan_transactions so the loan's outstanding balance, schedules, freeze rules and
     * its write-off stay untouched. Additive only: no existing row is changed.
     */
    public function up(): void
    {
        Schema::create('loan_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('write_off_id')->constrained('write_offs')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('method', 20)->default('CASH');
            $table->string('reference', 100)->nullable();
            $table->date('recovered_on');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('reversal_reason')->nullable();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['loan_id', 'reversed_at']);
            $table->index(['company_id', 'recovered_on']);
        });

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->foreignId('loan_recovery_id')->nullable()->after('loan_transaction_id')->constrained('loan_recoveries')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loan_recovery_id');
        });

        Schema::dropIfExists('loan_recoveries');
    }
};

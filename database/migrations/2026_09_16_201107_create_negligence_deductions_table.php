<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec §23 + §57 — staff negligence / loss deductions. HR creates the claim, FINANCE approves it and the
     * system recovers it from the employee's COMMISSION only (never from salary, never from the staff fund).
     * What is recovered goes to the PRINCIPAL A/C (operational capital); whatever the period's commission could
     * not cover stays outstanding and carries forward to the next commission automatically.
     */
    public function up(): void
    {
        Schema::create('negligence_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('recovered_amount', 15, 2)->default(0);
            $table->text('reason');
            $table->string('status')->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'employee_id', 'status']);
        });

        Schema::create('negligence_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('negligence_deduction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('salary_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period');
            $table->decimal('commission', 15, 2)->default(0);
            $table->decimal('amount', 15, 2);
            $table->decimal('outstanding_after', 15, 2)->default(0);
            $table->timestamps();
            $table->index(['negligence_deduction_id', 'period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('negligence_recoveries');
        Schema::dropIfExists('negligence_deductions');
    }
};

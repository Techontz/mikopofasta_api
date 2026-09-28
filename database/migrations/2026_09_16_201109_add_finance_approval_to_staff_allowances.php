<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec §24 + §58 — HR creates an allowance with a reason for a payroll period (pending), FINANCE approves it (approved /
     * awaiting payroll) and the payroll that pays it consumes it once (paid). Allowances that existed before this rule were
     * recurring and already flowed into payroll: they are kept as approved recurring allowances (status `active`).
     */
    public function up(): void
    {
        Schema::table('staff_allowances', function (Blueprint $table) {
            $table->string('reason')->default('other')->after('amount');
            $table->date('payroll_period')->nullable()->after('reason');
            $table->boolean('recurring')->default(false)->after('payroll_period');
            $table->foreignId('created_by')->nullable()->after('status')->constrained('employees')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('created_by')->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('employees')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
            $table->foreignId('payroll_run_id')->nullable()->after('rejection_reason')->constrained()->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->after('payroll_run_id');
        });

        DB::table('staff_allowances')->update(['recurring' => true, 'approved_at' => DB::raw('created_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staff_allowances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payroll_run_id');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['reason', 'payroll_period', 'recurring', 'approved_at', 'rejected_at', 'rejection_reason', 'paid_at']);
        });
    }
};

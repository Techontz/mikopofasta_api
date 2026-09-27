<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec §21 / §22 / §49 — commission is paid through its own flow instead of riding inside the monthly payroll:
     * calculated → awaiting_request (HR finalised the figures) → requested (HR payment request) → finance_approved → paid,
     * on any date, from a chosen paying account. Negligence (§23 / §57) is recovered from the commission when it is paid, so
     * negligence_recoveries point at the commission allocation they were taken from.
     *
     * The commission base, offset and zone allocation of the branch are snapshotted on each allocation so the payment keeps
     * the figures it was calculated from (NULL for allocations calculated before this migration).
     *
     * Existing allocations already linked to a payroll run keep that run as their (legacy) payment path: status `payroll`.
     * Their payroll lines, journals and payslips are left untouched.
     */
    public function up(): void
    {
        Schema::table('commission_allocations', function (Blueprint $table) {
            $table->decimal('offset_amount', 15, 2)->nullable()->after('distributable_profit');
            $table->decimal('commission_base', 15, 2)->nullable()->after('offset_amount');
            $table->decimal('zone_allocation', 15, 2)->nullable()->after('pool_amount');
            $table->string('payment_status', 30)->default('calculated')->after('amount');
            $table->decimal('negligence_deduction', 15, 2)->default(0)->after('payment_status');
            $table->decimal('net_amount', 15, 2)->nullable()->after('negligence_deduction');
            $table->foreignId('finalized_by')->nullable()->after('journal_entry_id')->constrained('employees')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable()->after('finalized_by');
            $table->foreignId('requested_by')->nullable()->after('finalized_at')->constrained('employees')->nullOnDelete();
            $table->timestamp('requested_at')->nullable()->after('requested_by');
            $table->foreignId('approved_by')->nullable()->after('requested_at')->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('employees')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->string('rejection_reason', 1000)->nullable()->after('rejected_at');
            $table->foreignId('paid_by')->nullable()->after('rejection_reason')->constrained('employees')->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->after('paid_by');
            $table->date('paid_on')->nullable()->after('paid_at');
            $table->string('paying_account', 40)->nullable()->after('paid_on');
            $table->foreignId('paying_branch_id')->nullable()->after('paying_account')->constrained('branches')->nullOnDelete();
            $table->foreignId('payment_journal_entry_id')->nullable()->after('paying_branch_id')->constrained('journal_entries')->nullOnDelete();
            $table->index(['company_id', 'payment_status']);
        });

        Schema::table('negligence_recoveries', function (Blueprint $table) {
            $table->foreignId('commission_allocation_id')->nullable()->after('payroll_run_id')->constrained('commission_allocations')->nullOnDelete();
        });

        $this->backfill();
    }

    /**
     * Allocations already carried by a payroll run keep that run as their legacy payment path.
     */
    public function backfill(): void
    {
        DB::table('commission_allocations')->whereNotNull('payroll_run_id')->where('payment_status', 'calculated')->update(['payment_status' => 'payroll']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('negligence_recoveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commission_allocation_id');
        });

        Schema::table('commission_allocations', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'payment_status']);
            $table->dropConstrainedForeignId('payment_journal_entry_id');
            $table->dropConstrainedForeignId('paying_branch_id');
            $table->dropConstrainedForeignId('paid_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('requested_by');
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn(['offset_amount', 'commission_base', 'zone_allocation', 'payment_status', 'negligence_deduction', 'net_amount', 'finalized_at', 'requested_at', 'approved_at', 'rejected_at', 'rejection_reason', 'paid_at', 'paid_on', 'paying_account']);
        });
    }
};

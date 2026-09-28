<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec §27 / §49 — a staff benefit claim (a payment out of the single STAFF FUND A/C) is no longer one immediate step:
     * prepared (HR, with the employee's recorded benefit entitlement) → finance_review → approved → paid, or rejected.
     *
     * Existing withdrawals were paid immediately when recorded, so they migrate as `paid` by their recorder on their
     * creation date, linked to the journal that already moved the money. Nothing in the ledger changes.
     */
    public function up(): void
    {
        Schema::table('staff_fund_withdrawals', function (Blueprint $table) {
            $table->string('status', 20)->default('prepared')->after('reason');
            $table->decimal('entitlement', 15, 2)->nullable()->after('amount');
            $table->foreignId('prepared_by')->nullable()->after('recorded_by')->constrained('employees')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable()->after('prepared_by');
            $table->foreignId('reviewed_by')->nullable()->after('prepared_at')->constrained('employees')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->foreignId('approved_by')->nullable()->after('reviewed_at')->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('employees')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->string('rejection_reason', 1000)->nullable()->after('rejected_at');
            $table->foreignId('paid_by')->nullable()->after('rejection_reason')->constrained('employees')->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->after('paid_by');
            $table->foreignId('journal_entry_id')->nullable()->after('paid_at')->constrained('journal_entries')->nullOnDelete();
            $table->index(['company_id', 'status']);
        });

        $this->backfill();
    }

    /**
     * Existing withdrawals (recorded before the claim workflow, all still `prepared` by the column default and without a
     * preparer) become paid by their recorder on their creation date, linked to the journal that moved the money.
     */
    public function backfill(): void
    {
        DB::table('staff_fund_withdrawals')->whereNull('prepared_by')->where('status', 'prepared')->update([
            'status' => 'paid',
            'prepared_by' => DB::raw('recorded_by'),
            'prepared_at' => DB::raw('created_at'),
            'paid_by' => DB::raw('recorded_by'),
            'paid_at' => DB::raw('created_at'),
        ]);

        DB::table('journal_entries')
            ->where('source_type', 'App\\Models\\StaffFundWithdrawal')
            ->whereNull('reversal_of_id')
            ->orderBy('id')
            ->get(['id', 'source_id'])
            ->each(fn (object $entry) => DB::table('staff_fund_withdrawals')->where('id', $entry->source_id)->whereNull('journal_entry_id')->update(['journal_entry_id' => $entry->id]));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staff_fund_withdrawals', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'status']);
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropConstrainedForeignId('paid_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('prepared_by');
            $table->dropColumn(['status', 'entitlement', 'prepared_at', 'reviewed_at', 'approved_at', 'rejected_at', 'rejection_reason', 'paid_at']);
        });
    }
};

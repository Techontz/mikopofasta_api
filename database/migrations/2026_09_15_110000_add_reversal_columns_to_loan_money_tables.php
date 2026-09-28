<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive trace columns for loan repayment / disbursement reversals (spec §21–22, §28–29) and write-off principal.
     * Only the new loan_transactions.journal_entry_id metadata column is backfilled (from the entry each repayment posted).
     */
    public function up(): void
    {
        Schema::table('loan_transactions', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->after('transaction_date')->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('journal_entry_id');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('employees')->nullOnDelete();
            $table->string('reversal_reason')->nullable()->after('reversed_by');
            $table->foreignId('reversal_journal_entry_id')->nullable()->after('reversal_reason')->constrained('journal_entries')->nullOnDelete();
        });

        Schema::table('penalty_payments', function (Blueprint $table) {
            $table->foreignId('loan_transaction_id')->nullable()->after('penalty_id')->constrained('loan_transactions')->nullOnDelete();
        });

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('amount');
            $table->foreignId('reversal_journal_entry_id')->nullable()->after('reversed_at')->constrained('journal_entries')->nullOnDelete();
        });

        Schema::table('loan_disbursements', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('reversal_reason')->nullable();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
        });

        Schema::table('write_offs', function (Blueprint $table) {
            $table->decimal('principal_amount', 15, 2)->nullable()->after('amount');
        });

        DB::table('journal_entries')
            ->where('source_type', 'App\\Models\\LoanTransaction')
            ->whereNull('reversal_of_id')
            ->orderBy('id')
            ->get(['id', 'source_id'])
            ->each(fn (object $entry) => DB::table('loan_transactions')
                ->where('id', $entry->source_id)
                ->whereNull('journal_entry_id')
                ->update(['journal_entry_id' => $entry->id]));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('write_offs', function (Blueprint $table) {
            $table->dropColumn('principal_amount');
        });

        Schema::table('loan_disbursements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_journal_entry_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_journal_entry_id');
            $table->dropColumn('reversed_at');
        });

        Schema::table('penalty_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loan_transaction_id');
        });

        Schema::table('loan_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_journal_entry_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });
    }
};

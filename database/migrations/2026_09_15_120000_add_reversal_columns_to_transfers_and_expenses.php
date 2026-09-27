<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reversal trace for internal transfers and expenses (Fund Flow Specification §19, §24, §28–29): the original row
     * stays, its status becomes "reversed" and it links the reversal journal entry, operator, time and reason.
     *
     * Only the new journal_entry_id metadata columns (and the still-empty bank_transfers.journal_entry_id values) are
     * backfilled from the non-reversal journal entry each record posted (source_type/source_id match).
     */
    public function up(): void
    {
        Schema::table('float_transfers', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->after('transfer_date')->constrained('journal_entries')->nullOnDelete();
        });

        Schema::table('hq_transactions', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->after('approved_at')->constrained('journal_entries')->nullOnDelete();
        });

        foreach (['float_transfers', 'bank_transfers', 'hq_transactions', 'expense_requests'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->timestamp('reversed_at')->nullable();
                $table->foreignId('reversed_by')->nullable()->constrained('employees')->nullOnDelete();
                $table->string('reversal_reason')->nullable();
                $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            });
        }

        foreach (['float_transfers' => 'App\\Models\\FloatTransfer', 'bank_transfers' => 'App\\Models\\BankTransfer', 'hq_transactions' => 'App\\Models\\HqTransaction'] as $tableName => $sourceType) {
            DB::table('journal_entries')
                ->where('source_type', $sourceType)
                ->whereNull('reversal_of_id')
                ->orderBy('id')
                ->get(['id', 'source_id'])
                ->each(fn (object $entry) => DB::table($tableName)
                    ->where('id', $entry->source_id)
                    ->whereNull('journal_entry_id')
                    ->update(['journal_entry_id' => $entry->id]));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['expense_requests', 'hq_transactions', 'bank_transfers', 'float_transfers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('reversal_journal_entry_id');
                $table->dropConstrainedForeignId('reversed_by');
                $table->dropColumn(['reversed_at', 'reversal_reason']);
            });
        }

        Schema::table('hq_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
        });

        Schema::table('float_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
        });
    }
};

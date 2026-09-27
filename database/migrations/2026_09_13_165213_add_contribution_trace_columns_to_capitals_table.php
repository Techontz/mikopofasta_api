<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each capital row is one shareholder contribution. It now records which company account received the money
     * (company_cash = COMPANY ACCOUNT, or bank + bank_account_id), who recorded it, when it was contributed, the
     * journal entry that posted it and an idempotency key so a retried request cannot post twice.
     *
     * Existing rows are backfilled only from their own journal entry (receiving account = the debited account,
     * recorded_by = the entry's employee) and their own created_at; nothing is invented when a value is absent.
     */
    public function up(): void
    {
        Schema::table('capitals', function (Blueprint $table) {
            $table->string('receiving_account', 30)->nullable()->after('pay_method');
            $table->foreignId('bank_account_id')->nullable()->after('receiving_account')->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->after('receipt_file_name')->constrained('employees')->nullOnDelete();
            $table->timestamp('contributed_at')->nullable()->after('recorded_by');
            $table->foreignId('journal_entry_id')->nullable()->unique()->after('contributed_at')->constrained('journal_entries');
            $table->string('idempotency_key', 100)->nullable()->unique()->after('journal_entry_id');
        });

        DB::table('capitals')->orderBy('id')->each(function (object $capital): void {
            $entry = DB::table('journal_entries')
                ->where('source_type', 'App\\Models\\Capital')
                ->where('source_id', $capital->id)
                ->whereNull('reversal_of_id')
                ->orderBy('id')
                ->first();

            $debit = $entry === null ? null : DB::table('journal_lines')
                ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
                ->where('journal_lines.journal_entry_id', $entry->id)
                ->where('journal_lines.debit', '>', 0)
                ->select('accounts.key', 'accounts.bank_account_id')
                ->first();

            DB::table('capitals')->where('id', $capital->id)->update([
                'journal_entry_id' => $entry?->id,
                'recorded_by' => $entry?->employee_id,
                'receiving_account' => $debit?->key,
                'bank_account_id' => $debit?->bank_account_id,
                'contributed_at' => $capital->created_at,
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('capitals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['receiving_account', 'contributed_at', 'idempotency_key']);
        });
    }
};

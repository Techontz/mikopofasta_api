<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a disbursement's money comes from and the journal entry that posted it, so the chain
     * customer → loan → approval → disbursement → source account → journal entry is traceable.
     *
     * source_account: "cash" = the loan branch's PRINCIPAL A/C (the branch lending cash fund, funded by float from the
     * COMPANY ACCOUNT) or "bank" = the company bank account in source_bank_account_id. Rows that existed before this
     * column were all posted from the branch PRINCIPAL A/C, so a null source is read as "cash".
     */
    public function up(): void
    {
        Schema::table('loan_disbursements', function (Blueprint $table) {
            $table->string('source_account', 20)->nullable()->after('amount');
            $table->foreignId('source_bank_account_id')->nullable()->after('source_account')->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->unique()->after('source_bank_account_id')->constrained('journal_entries');
        });

        DB::table('loan_disbursements')->where('status', 'success')->whereNull('journal_entry_id')->orderBy('id')->each(function (object $disbursement): void {
            $entry = DB::table('journal_entries')
                ->where('source_type', 'App\\Models\\Loan')
                ->where('source_id', $disbursement->loan_id)
                ->where('description', 'like', 'LOAN DISBURSEMENT%')
                ->whereNull('reversal_of_id')
                ->orderBy('id')
                ->first();

            if ($entry === null || DB::table('loan_disbursements')->where('journal_entry_id', $entry->id)->exists()) {
                return;
            }

            $credited = DB::table('journal_lines')
                ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
                ->where('journal_lines.journal_entry_id', $entry->id)
                ->where('journal_lines.credit', '>', 0)
                ->whereIn('accounts.key', ['principal', 'bank'])
                ->select('accounts.key', 'accounts.bank_account_id')
                ->first();

            DB::table('loan_disbursements')->where('id', $disbursement->id)->update([
                'journal_entry_id' => $entry->id,
                'source_account' => match ($credited?->key) {
                    'principal' => 'cash',
                    'bank' => 'bank',
                    default => null,
                },
                'source_bank_account_id' => $credited?->bank_account_id,
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_disbursements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropConstrainedForeignId('source_bank_account_id');
            $table->dropColumn('source_account');
        });
    }
};

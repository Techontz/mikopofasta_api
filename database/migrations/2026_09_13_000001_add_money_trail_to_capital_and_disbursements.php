<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The money trail: shareholder → contribution → company account → transfer →
 * disbursement → loan. See docs/modules/capital.md, "Money trail".
 *
 * Additive only. No journal entry or line is touched — those are immutable —
 * and existing rows are backfilled from what their own posted entries already
 * say, never from a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capital_contributions', function (Blueprint $table): void {
            // CAP-0000001, or the bank/mobile-money transaction number the
            // shareholder paid with. Unique, so the same payment cannot be
            // recorded twice.
            $table->string('reference', 60)->nullable()->after('id');

            // The registered company account the money landed in, when it
            // was not cash.
            $table->foreignId('bank_account_id')->nullable()->after('pay_method')
                ->constrained('bank_accounts')->restrictOnDelete();

            // The ledger account debited — the company account affected.
            $table->foreignId('received_account_id')->nullable()->after('bank_account_id')
                ->constrained('chart_of_accounts')->restrictOnDelete();

            // The shareholder's own account the money came from.
            $table->string('source_account_name', 150)->nullable()->after('received_account_id');
            $table->string('source_account_number', 60)->nullable()->after('source_account_name');
        });

        DB::table('capital_contributions')->orderBy('id')->each(function (object $row): void {
            $received = $row->journal_entry_id === null ? null : DB::table('journal_entry_lines')
                ->where('journal_entry_id', $row->journal_entry_id)
                ->where('debit_amount', '>', 0)
                ->orderBy('id')
                ->value('account_id');

            DB::table('capital_contributions')->where('id', $row->id)->update([
                'reference' => 'CAP-'.str_pad((string) $row->id, 7, '0', STR_PAD_LEFT),
                'received_account_id' => $received,
            ]);
        });

        Schema::table('capital_contributions', function (Blueprint $table): void {
            $table->string('reference', 60)->nullable(false)->change();
            $table->unique('reference', 'capital_contributions_reference_unique');
        });

        Schema::table('disbursement_batches', function (Blueprint $table): void {
            // The company account the payout leaves — chosen at preparation.
            $table->foreignId('funding_account_id')->nullable()->after('channel')
                ->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('funding_bank_account_id')->nullable()->after('funding_account_id')
                ->constrained('bank_accounts')->restrictOnDelete();

            // The entry the successful settlement posted. Unique: one batch,
            // one entry.
            $table->foreignId('journal_entry_id')->nullable()->after('status')
                ->constrained('journal_entries')->restrictOnDelete();

            /*
             * Equal to loan_id once — and only once — a batch succeeds.
             *
             * A UNIQUE index over a column that is NULL for every other batch
             * is how the database itself refuses a second successful
             * disbursement of the same loan, whatever the application does.
             */
            $table->unsignedBigInteger('settled_loan_id')->nullable()->after('journal_entry_id');

            $table->unique('journal_entry_id', 'disbursement_batches_journal_entry_unique');
            $table->unique('settled_loan_id', 'disbursement_batches_settled_loan_unique');
        });

        // Existing successful batches: link the entry already posted for the loan.
        DB::table('disbursement_batches')
            ->where('status', 'success')
            ->orderByDesc('id')
            ->get(['id', 'loan_id'])
            ->unique('loan_id')
            ->each(function (object $batch): void {
                $entryId = DB::table('journal_entries')
                    ->where('source_type', 'loan_disbursement')
                    ->where('source_id', $batch->loan_id)
                    ->where('is_reversal', false)
                    ->orderBy('id')
                    ->value('id');

                DB::table('disbursement_batches')->where('id', $batch->id)->update([
                    'settled_loan_id' => $batch->loan_id,
                    'journal_entry_id' => $entryId,
                ]);
            });
    }

    public function down(): void
    {
        // Foreign keys first: MySQL will not drop an index a key still uses.
        Schema::table('disbursement_batches', function (Blueprint $table): void {
            $table->dropForeign(['funding_account_id']);
            $table->dropForeign(['funding_bank_account_id']);
            $table->dropForeign(['journal_entry_id']);
        });

        Schema::table('disbursement_batches', function (Blueprint $table): void {
            $table->dropUnique('disbursement_batches_journal_entry_unique');
            $table->dropUnique('disbursement_batches_settled_loan_unique');
            $table->dropColumn(['funding_account_id', 'funding_bank_account_id', 'journal_entry_id', 'settled_loan_id']);
        });

        Schema::table('capital_contributions', function (Blueprint $table): void {
            $table->dropForeign(['bank_account_id']);
            $table->dropForeign(['received_account_id']);
        });

        Schema::table('capital_contributions', function (Blueprint $table): void {
            $table->dropUnique('capital_contributions_reference_unique');
            $table->dropColumn(['reference', 'bank_account_id', 'received_account_id', 'source_account_name', 'source_account_number']);
        });
    }
};

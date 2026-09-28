<?php

use App\Enums\TransactionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicit business-event type on every journal entry (Fund Flow Specification §28, §32).
     *
     * Existing entries are backfilled from their source model, description prefix and debited/credited accounts
     * ({@see TransactionType::infer()}); unknown mappings stay null. Only this new metadata column is written —
     * amounts, lines and every other column are untouched.
     */
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('transaction_type', 50)->nullable()->after('description')->index();
        });

        DB::table('journal_entries')->select(['id', 'source_type', 'description', 'reversal_of_id'])->orderBy('id')
            ->chunkById(500, function ($entries): void {
                $keys = DB::table('journal_lines')
                    ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
                    ->whereIn('journal_lines.journal_entry_id', $entries->pluck('id'))
                    ->get(['journal_lines.journal_entry_id', 'accounts.key', 'journal_lines.debit', 'journal_lines.credit'])
                    ->groupBy('journal_entry_id');

                foreach ($entries as $entry) {
                    $lines = $keys->get($entry->id, collect());
                    $type = TransactionType::infer(
                        $entry->source_type,
                        (string) $entry->description,
                        $lines->filter(fn ($line): bool => (float) $line->debit > 0)->pluck('key')->values()->all(),
                        $lines->filter(fn ($line): bool => (float) $line->credit > 0)->pluck('key')->values()->all(),
                        $entry->reversal_of_id !== null,
                    );

                    if ($type !== null) {
                        DB::table('journal_entries')->where('id', $entry->id)->update(['transaction_type' => $type->value]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex(['transaction_type']);
            $table->dropColumn('transaction_type');
        });
    }
};

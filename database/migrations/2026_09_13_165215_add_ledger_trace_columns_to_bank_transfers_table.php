<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Company fund transfers (COMPANY ACCOUNT ↔ bank) reuse bank_transfers. Each posted transfer keeps its journal
     * entry, an optional reference typed by the user, and an idempotency key so a retried request cannot post twice.
     */
    public function up(): void
    {
        Schema::table('bank_transfers', function (Blueprint $table) {
            $table->string('reference', 100)->nullable()->after('charge');
            $table->foreignId('journal_entry_id')->nullable()->after('reference')->constrained('journal_entries');
            $table->string('idempotency_key', 100)->nullable()->unique()->after('journal_entry_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['reference', 'idempotency_key']);
        });
    }
};

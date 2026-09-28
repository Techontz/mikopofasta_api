<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operator of a capital contribution reversal (Fund Flow Specification §28–29). `capitals` already carries
     * reversed_at, reversal_journal_entry_id and reversal_reason; existing rows are not changed.
     */
    public function up(): void
    {
        Schema::table('capitals', function (Blueprint $table) {
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('employees')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('capitals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_by');
        });
    }
};

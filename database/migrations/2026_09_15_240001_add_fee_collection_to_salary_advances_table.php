<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * C2 (salary-advance approval fee): the fee is income only when it is actually collected. Approval no longer posts it; a
     * separate collection records who collected it, how, and its Dr LOAN FEE A/C / Cr FEE INCOME journal. Additive, nullable
     * columns only — advances whose fee was posted at approval (legacy) keep their journal and are read as collected by the API.
     */
    public function up(): void
    {
        Schema::table('salary_advances', function (Blueprint $table) {
            $table->timestamp('fee_collected_at')->nullable()->after('approved_at');
            $table->foreignId('fee_collected_by')->nullable()->after('fee_collected_at')->constrained('employees')->nullOnDelete();
            $table->string('fee_collection_method', 20)->nullable()->after('fee_collected_by');
            $table->string('fee_collection_reference')->nullable()->after('fee_collection_method');
            $table->foreignId('fee_journal_entry_id')->nullable()->after('fee_collection_reference')->constrained('journal_entries')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salary_advances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fee_journal_entry_id');
            $table->dropColumn(['fee_collection_reference', 'fee_collection_method']);
            $table->dropConstrainedForeignId('fee_collected_by');
            $table->dropColumn('fee_collected_at');
        });
    }
};

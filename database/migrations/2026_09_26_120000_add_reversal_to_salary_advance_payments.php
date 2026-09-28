<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A salary advance deposit is never deleted: its reversal (maker/checker, Reversal Requests) mirrors its journal and marks
 * the deposit reversed, so it drops out of what was paid and the advance owes that amount again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_advance_payments', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('paid_on');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('employees')->nullOnDelete();
            $table->string('reversal_reason', 500)->nullable()->after('reversed_by');
            $table->foreignId('reversal_journal_entry_id')->nullable()->after('reversal_reason')->constrained('journal_entries')->nullOnDelete();
            $table->index(['salary_advance_id', 'reversed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('salary_advance_payments', function (Blueprint $table) {
            $table->dropIndex(['salary_advance_id', 'reversed_at']);
            $table->dropConstrainedForeignId('reversal_journal_entry_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });
    }
};

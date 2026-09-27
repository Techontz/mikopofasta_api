<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expense approval trail and tagging (Documents: branch expenses by Finance, large/HQ by Admin;
     * reports need branch vs HQ tagging and the account the expense was paid from).
     */
    public function up(): void
    {
        Schema::table('expense_requests', function (Blueprint $table) {
            $table->string('paid_from_account')->nullable()->after('bank_account_id');
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('journal_entry_id')->nullable()->after('approved_at')->constrained('journal_entries')->nullOnDelete();
            $table->index(['company_id', 'scope', 'status']);
        });

        Schema::table('bank_transfers', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('bank_account_id')->constrained('employees')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('employees')->nullOnDelete();
        });

        Schema::table('hq_transactions', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hq_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
        });

        Schema::table('bank_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('employee_id');
        });

        Schema::table('expense_requests', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'scope', 'status']);
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['paid_from_account', 'approved_at']);
        });
    }
};

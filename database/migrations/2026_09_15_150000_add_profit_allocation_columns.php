<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive trace columns for the profit chain (Fund Flow Specification §10, §13–16, §36C/F):
     *  - penalties: the accrual journal (Dr PENALTY RECEIVABLE / Cr PENALTY INCOME) and the waiver journal of accrued penalties;
     *  - commission_allocations: the profit-allocation journal (Dr PROFIT ACCOUNT / Cr COMMISSION PAYABLE);
     *  - dividend_declarations: the profit chain of new-rule declarations (distributable → commission → base) and the
     *    fund-movement journal of the reinvestment (Dr PRINCIPAL / Cr branch income pools).
     *
     * Existing rows are not touched: NULL means a legacy row (cash-basis penalty, commission as expense, reinvestment to capital).
     */
    public function up(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->foreignId('accrual_journal_entry_id')->nullable()->after('penalty_date')->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('waiver_journal_entry_id')->nullable()->after('accrual_journal_entry_id')->constrained('journal_entries')->nullOnDelete();
        });

        Schema::table('commission_allocations', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->after('payroll_run_id')->constrained('journal_entries')->nullOnDelete();
        });

        Schema::table('dividend_declarations', function (Blueprint $table) {
            $table->string('allocation_rule', 40)->nullable()->after('profit_source');
            $table->decimal('distributable_profit', 15, 2)->nullable()->after('allocation_rule');
            $table->decimal('commission_amount', 15, 2)->nullable()->after('distributable_profit');
            $table->decimal('base_amount', 15, 2)->nullable()->after('commission_amount');
            $table->foreignId('reinvestment_journal_entry_id')->nullable()->after('journal_entry_id')->constrained('journal_entries')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dividend_declarations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reinvestment_journal_entry_id');
            $table->dropColumn(['allocation_rule', 'distributable_profit', 'commission_amount', 'base_amount']);
        });

        Schema::table('commission_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
        });

        Schema::table('penalties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('waiver_journal_entry_id');
            $table->dropConstrainedForeignId('accrual_journal_entry_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec §20, §23 and §26:
     *  - `company_fund_percent`: the company's own staff fund contribution (§26 default 20 %) next to the
     *    employee's `staff_fund_percent`. It is an OBLIGATION, never fund cash.
     *  - payroll lines and salary payments carry the company contribution and the negligence recovery.
     *  - payroll runs record the date their expense journals were posted on (§20: the period's last day) and,
     *    when the payroll month was already closed in the ledger, why that date could not be used.
     */
    public function up(): void
    {
        Schema::table('hrm_settings', function (Blueprint $table) {
            $table->decimal('company_fund_percent', 5, 2)->default(20)->after('staff_fund_percent');
        });

        Schema::table('payroll_items', function (Blueprint $table) {
            $table->decimal('negligence', 15, 2)->default(0)->after('deduction');
            $table->decimal('company_fund', 15, 2)->default(0)->after('staff_fund');
        });

        Schema::table('salary_payments', function (Blueprint $table) {
            $table->decimal('negligence', 15, 2)->default(0)->after('deduction');
            $table->decimal('company_fund', 15, 2)->default(0)->after('staff_fund');
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->date('expense_date')->nullable()->after('commission_status');
            $table->string('expense_period_note')->nullable()->after('expense_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn(['expense_date', 'expense_period_note']);
        });
        Schema::table('salary_payments', function (Blueprint $table) {
            $table->dropColumn(['negligence', 'company_fund']);
        });
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropColumn(['negligence', 'company_fund']);
        });
        Schema::table('hrm_settings', function (Blueprint $table) {
            $table->dropColumn('company_fund_percent');
        });
    }
};

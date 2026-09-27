<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product owner ruling (2026-09-17) on specification §26: there is no separate company staff fund contribution. Salary
 * expense is the full basic salary; the 20 % withheld from it is the fund's only contribution and enters the STAFF FUND
 * A/C as cash. The company-contribution columns added the day before carried no posted history, so they are removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hrm_settings', function (Blueprint $table) {
            $table->dropColumn('company_fund_percent');
        });
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropColumn('company_fund');
        });
        Schema::table('salary_payments', function (Blueprint $table) {
            $table->dropColumn('company_fund');
        });
    }

    public function down(): void
    {
        Schema::table('hrm_settings', function (Blueprint $table) {
            $table->decimal('company_fund_percent', 5, 2)->default(20)->after('staff_fund_percent');
        });
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->decimal('company_fund', 15, 2)->default(0)->after('staff_fund');
        });
        Schema::table('salary_payments', function (Blueprint $table) {
            $table->decimal('company_fund', 15, 2)->default(0)->after('staff_fund');
        });
    }
};

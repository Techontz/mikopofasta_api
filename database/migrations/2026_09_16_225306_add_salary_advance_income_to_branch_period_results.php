<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Specification §9: salary advance profit is its own income category, so a closed month stores it beside interest, fees,
 * penalties and recoveries. Months closed before it existed booked that profit inside interest income and read 0 here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_period_results', function (Blueprint $table) {
            $table->decimal('salary_advance_income', 15, 2)->default(0)->after('reserve_amount');
        });
    }

    public function down(): void
    {
        Schema::table('branch_period_results', function (Blueprint $table) {
            $table->dropColumn('salary_advance_income');
        });
    }
};

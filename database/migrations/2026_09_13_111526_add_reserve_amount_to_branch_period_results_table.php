<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reserve cut from interest during the period (ACCOUNT OVERVIEW: "Profit = Interest - Expenses
     * (Reserve tayari imekatwa)"). interest_income on the result row is stored net of this amount.
     */
    public function up(): void
    {
        Schema::table('branch_period_results', function (Blueprint $table) {
            $table->decimal('reserve_amount', 15, 2)->default(0)->after('interest_income');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branch_period_results', function (Blueprint $table) {
            $table->dropColumn('reserve_amount');
        });
    }
};

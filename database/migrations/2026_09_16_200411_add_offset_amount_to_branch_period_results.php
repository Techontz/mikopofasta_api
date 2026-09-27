<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The offset settled in the period, per branch (specification §15): old debt cleared out of a top-up rather than paid in
 * cash. It is excluded from the commission base and added back before dividend and reinvestment, so it has to be stored
 * with the period it belongs to — a later top-up must not change a commission that has already been calculated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_period_results', function (Blueprint $table) {
            $table->decimal('offset_amount', 15, 2)->default(0)->after('distributable_profit');
        });
    }

    public function down(): void
    {
        Schema::table('branch_period_results', function (Blueprint $table) {
            $table->dropColumn('offset_amount');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loan Start Date (enhancement §3.2): the date the customer makes the first repayment. The schedule is generated from it
 * at disbursement; loans without one (all earlier loans) keep the first due date one period after disbursement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->date('start_date')->nullable()->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->dropColumn('start_date');
        });
    }
};

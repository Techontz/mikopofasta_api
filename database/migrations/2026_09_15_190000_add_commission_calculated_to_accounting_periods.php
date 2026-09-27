<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that the commission of a closed month has been calculated, even when the calculation stored no allocation (a branch
 * pool without eligible staff returns to profit, rule 4). The dividend base subtracts only calculated commission (rule 5) and a
 * declaration made before commission was calculated locks commission for the month. Additive, nullable: existing periods keep
 * NULL (their stored allocations, if any, still count as calculated).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_periods', function (Blueprint $table): void {
            $table->timestamp('commission_calculated_at')->nullable()->after('closed_at');
            $table->foreignId('commission_calculated_by')->nullable()->after('commission_calculated_at')->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounting_periods', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('commission_calculated_by');
            $table->dropColumn('commission_calculated_at');
        });
    }
};

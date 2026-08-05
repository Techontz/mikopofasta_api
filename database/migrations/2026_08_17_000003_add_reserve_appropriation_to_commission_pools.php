<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the reserve visible in the commission pool — Decision Register D1, C1.
 *
 * This column exists to keep an economic promise, not to add a feature.
 *
 * Before D1, reserve was netted out of Interest Income at the moment of
 * collection, so `commission_pools.branch_profit` was already net of it and §8
 * could say "(Reserve already netted out)". D1 moved the appropriation to the
 * close, which leaves branch profit GROSS of reserve. Left alone, every
 * commission pool in the system would have silently grown by the reserve share
 * of interest — a change nobody asked for, arriving as a side effect of a
 * timing decision.
 *
 * So the reserve is now deducted explicitly, first, in
 * CommissionCalculator::computePool(). That matches the client's own words —
 * "kwenye hiyo faida reserve inatolewa kwanza maana ndo inalinda mtaji", from
 * that profit the reserve is taken first because it protects the capital — and
 * it keeps the pool arithmetically where it was.
 *
 * Storing it on the row rather than recomputing means a manager asking why a
 * pool is what it is can be shown every deduction in order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_pools', function (Blueprint $table): void {
            // Never negative: a loss-making branch appropriates no reserve, so
            // the deduction is zero rather than a credit back to the pool.
            $table->decimal('reserve_appropriation', 18, 2)
                ->default(0)
                ->after('branch_profit');
        });
    }

    public function down(): void
    {
        Schema::table('commission_pools', function (Blueprint $table): void {
            $table->dropColumn('reserve_appropriation');
        });
    }
};

<?php

declare(strict_types=1);

use App\Domain\Hr\Enums\DeductionCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM → Payroll Settings, and the reason behind a hand-entered deduction.
 *
 * Three halves:
 *
 *   payroll_settings   the Staff Fund contribution and the commission rates
 *                      (pool, HQ hold, zone override), which were class
 *                      constants. The client sets them. Seeded with the values
 *                      the constants held, so nothing is paid differently
 *                      until somebody changes them.
 *
 *   commission_pools   gains `hq_hold_percentage`. The hold rate is editable
 *                      now, so a pool generated at 2% must still read as 2%
 *                      after it becomes 3% — exactly as `pool_percentage` is
 *                      already kept. Existing pools were all generated at 2%.
 *
 *   staff_deductions   gains `category` — negligence, loss or other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_settings', function (Blueprint $table): void {
            $table->id();

            /* Three decimals, matching `commission_pools.pool_percentage`. */
            $table->decimal('staff_fund_percentage', 6, 3)->default(10);
            $table->decimal('commission_pool_percentage', 6, 3)->default(20);
            $table->decimal('hq_hold_percentage', 6, 3)->default(2);
            $table->decimal('zone_override_percentage', 6, 3)->default(5);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('payroll_settings')->insert([
            'staff_fund_percentage' => 10,
            'commission_pool_percentage' => 20,
            'hq_hold_percentage' => 2,
            'zone_override_percentage' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('commission_pools', function (Blueprint $table): void {
            $table->decimal('hq_hold_percentage', 6, 3)->nullable()->after('hq_hold_amount');
        });

        DB::table('commission_pools')->update(['hq_hold_percentage' => 2]);

        Schema::table('staff_deductions', function (Blueprint $table): void {
            $table->enum('category', DeductionCategory::values())
                ->default(DeductionCategory::Other->value)
                ->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('staff_deductions', function (Blueprint $table): void {
            $table->dropColumn('category');
        });

        Schema::table('commission_pools', function (Blueprint $table): void {
            $table->dropColumn('hq_hold_percentage');
        });

        Schema::dropIfExists('payroll_settings');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How distributable profit splits — ACCOUNT OVERVIEW §I.16.
 *
 *     "Profit → Dividend Account
 *      Split: 70% → Principal (Reinvestment) 30% → Shareholders"
 *
 * The percentages are stored rather than written into the close, because the
 * handwritten note ("SHARE HOLDER & CAPITAL", item ⑦) describes the split as a
 * policy — "some percentage should go to the main capital and remaining goes
 * to the Gawio account" — without fixing it. Seeded at the documented 70/30 so
 * behaviour on day one is exactly what the document specifies, and changeable
 * afterwards without a deploy.
 *
 * Singleton, the same shape `reserve_settings` and `company_profiles` already
 * use: one row, created on first read. A second row would mean two answers to
 * "what is the split", and the close would have to pick one.
 *
 * Also records what the period distributed, alongside the reserve columns that
 * are already there — a closed period must be able to explain itself without
 * re-deriving figures from a setting that may since have changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribution_settings', function (Blueprint $table): void {
            $table->id();

            /*
             * Two decimals, matching `reserve_settings.percentage`. Stored
             * separately rather than as one figure plus a remainder: both
             * halves are named in the document, and a reader of this table
             * should not have to subtract to learn the dividend share.
             */
            $table->decimal('reinvestment_percentage', 5, 2)->default(70);
            $table->decimal('dividend_percentage', 5, 2)->default(30);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        /* The documented split, present from the first close. */
        DB::table('distribution_settings')->insert([
            'reinvestment_percentage' => 70,
            'dividend_percentage' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('accounting_periods', function (Blueprint $table): void {
            /*
             * What this period actually distributed, and at which rates.
             * Recorded because the setting is editable: a period closed under
             * 70/30 must still read as 70/30 after somebody changes it to
             * 60/40, exactly as `reserve_percentage` is already kept here.
             */
            $table->decimal('reinvestment_percentage', 5, 2)->nullable()->after('reserve_appropriated');
            $table->decimal('dividend_percentage', 5, 2)->nullable()->after('reinvestment_percentage');
            $table->decimal('reinvested_amount', 18, 2)->nullable()->after('dividend_percentage');
            $table->decimal('dividend_amount', 18, 2)->nullable()->after('reinvested_amount');
            $table->foreignId('distribution_journal_entry_id')->nullable()
                ->after('reserve_journal_entry_id')
                ->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounting_periods', function (Blueprint $table): void {
            $table->dropForeign(['distribution_journal_entry_id']);
            $table->dropColumn([
                'reinvestment_percentage', 'dividend_percentage',
                'reinvested_amount', 'dividend_amount', 'distribution_journal_entry_id',
            ]);
        });

        Schema::dropIfExists('distribution_settings');
    }
};

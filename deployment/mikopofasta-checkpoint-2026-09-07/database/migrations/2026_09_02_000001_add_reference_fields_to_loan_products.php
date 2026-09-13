<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The five loan-product facts the Loan Category screen shows and the table
 * could not hold.
 *
 * WHAT WAS MISSING. The legacy Loan Category list states, per product: how many
 * repayments it runs over ("1 - 3"), whether the instalment is deducted at
 * source, which tier signs its loans off, the top-up percentage and the
 * take-home percentage. All five are decisions an institution makes per
 * product, all five were being recorded somewhere outside this system, and
 * none of them had a column — so the screen could show a product's interest and
 * its amount band and then go quiet about the terms that decide what the
 * customer actually receives.
 *
 * NUMBER OF REPAYMENTS IS NOT THE TENURE. `min_tenure_days` and
 * `max_tenure_days` say how long the loan runs; these say how many instalments
 * it is broken into. A 90-day loan repaid weekly and the same loan repaid
 * monthly are different products to a borrower, and the legacy screen shows
 * both figures for exactly that reason.
 *
 * APPROVAL IS A STAGE, NOT A WORD. The legacy screen offers "branch", "hq" and
 * "zone manager" from a fixed list. This application already has the
 * configurable chain those three are a hardcoded shadow of —
 * `loan_approval_stages`, which an administrator edits at Administration → Loan
 * Approval Chain. So this is a foreign key to that table, not an enum: an
 * institution that runs a two-tier chain, or calls its tiers something else,
 * gets its own names here with no code change. Nullable, because a product that
 * names no stage simply walks the whole configured chain, which is what every
 * existing product does today.
 *
 * ADDITIVE AND SAFE. Every column is nullable or defaulted, no row is written,
 * and nothing existing changes meaning. `allows_deduction` defaults FALSE and
 * the percentages default NULL, so an existing product reads exactly as it
 * behaved before this ran — none of them silently acquires a deduction or a
 * top-up ceiling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_products', function (Blueprint $table): void {
            /*
             * How many instalments, not how many days. "1 - 3" on the legacy
             * screen. Nullable: a product that has never stated a range keeps
             * deriving its schedule from the tenure and the cadence, as it does
             * today.
             */
            $table->unsignedSmallInteger('min_repayments')->nullable()->after('max_tenure_days');
            $table->unsignedSmallInteger('max_repayments')->nullable()->after('min_repayments');

            /* Whether the instalment is taken at source — from a salary, for a
               product lent against employment. */
            $table->boolean('allows_deduction')->default(false)->after('max_repayments');

            /*
             * Which tier signs these loans off. A stage from the configured
             * chain, never one of three hardcoded words. nullOnDelete so
             * retiring a stage cannot take a product's configuration with it —
             * the product falls back to walking the whole chain.
             */
            $table->foreignId('approval_stage_id')
                ->nullable()
                ->after('allows_deduction')
                ->constrained('loan_approval_stages')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            /* The share of an existing loan a customer may top up against, and
               the share of income the instalment may consume. Percentages, so
               5,2 holds 100.00 with room to spare. */
            $table->decimal('topup_percent', 5, 2)->nullable()->after('approval_stage_id');
            $table->decimal('take_home_percent', 5, 2)->nullable()->after('topup_percent');
        });
    }

    public function down(): void
    {
        Schema::table('loan_products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approval_stage_id');
            $table->dropColumn([
                'min_repayments', 'max_repayments', 'allows_deduction',
                'topup_percent', 'take_home_percent',
            ]);
        });
    }
};

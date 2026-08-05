<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two client decisions, one migration.
 *
 * ## Decision 2 — Reducing EMI becomes the default
 *
 * Both reducing-balance formulas stay. Equal Principal is explicitly not
 * removed, so existing products and every loan already priced on them keep
 * their meaning; what changes is only which one a NEW product starts on.
 *
 * `is_default` is a column rather than a constant because that is the whole
 * point of formulas being master data. Should the business change its mind, it
 * is a row update, not a deploy — and exactly one row can hold it, which the
 * unique index enforces rather than trusting the seeder to be careful.
 *
 * ## Decision 4 — the interest period is left open, but made switchable
 *
 * "DO NOT implement any assumption. Leave this configurable." So this creates
 * the mechanism and deliberately does not choose:
 *
 *   AS_CONFIGURED  the rate means exactly what it has always meant in this
 *                  system — the figure the product carries, applied as each
 *                  formula defines. Default, active, and byte-for-byte
 *                  identical to today's arithmetic.
 *
 *   PER_ANNUM      the rate is annual and the engine converts it to the loan's
 *                  cadence. Seeded but INACTIVE, so it cannot be selected
 *                  until the client confirms.
 *
 * Enabling APR later is `UPDATE interest_rate_bases SET is_active = 1` and
 * assigning it to a product. No strategy, no engine and no schedule code
 * changes — which is the architectural requirement the decision actually
 * stated.
 *
 * `loan_products.interest_rate_basis_id` is nullable, and null means "the
 * default basis". That keeps every existing product row valid and untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interest_rate_bases', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('code', 40)->unique();
            $table->text('description')->nullable();

            /*
             * Which basis a product with none configured is priced on. Unique
             * so the question "what is the default?" cannot have two answers —
             * a nullable unique column permits many NULLs but only one `1`.
             */
            $table->boolean('is_default')->nullable()->unique();

            // An inactive basis is implemented but not offered. That is how
            // PER_ANNUM waits for a decision without waiting for a deploy.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('interest_formulas', function (Blueprint $table): void {
            $table->boolean('is_default')->nullable()->unique()->after('description');
        });

        Schema::table('loan_products', function (Blueprint $table): void {
            $table->foreignId('interest_rate_basis_id')->nullable()->after('interest_rate')
                ->constrained('interest_rate_bases')->restrictOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('loan_products', function (Blueprint $table): void {
            $table->dropForeign(['interest_rate_basis_id']);
            $table->dropColumn('interest_rate_basis_id');
        });

        Schema::table('interest_formulas', function (Blueprint $table): void {
            $table->dropColumn('is_default');
        });

        Schema::dropIfExists('interest_rate_bases');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow a branch to exist without a recorded phone number.
 *
 * The three legacy branches are known by name only — no captured screen shows a
 * branch's phone number. The column was NOT NULL, which meant seeding them
 * required inventing three phone numbers, and an invented phone number in a
 * microfinance system is worse than an absent one: someone will eventually dial
 * it.
 *
 * Branches created through the API still require a phone; that rule lives in
 * the form request, where it belongs, rather than in the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('phone', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('phone', 20)->nullable(false)->change();
        });
    }
};

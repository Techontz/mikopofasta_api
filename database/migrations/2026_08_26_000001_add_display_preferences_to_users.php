<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a person wants the system presented to them.
 *
 * Presentation only. Nothing here changes what a query returns, what a report
 * totals, or how an amount is stored — a date format is a rendering choice,
 * and treating it as anything more would make two users disagree about the
 * same record.
 *
 * All nullable, and null means "follow the system default". That matters more
 * than it looks: a user who never opens this screen must behave exactly as
 * they did before it existed, which a column with a non-null default would
 * quietly break for everyone at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            /* IANA identifier — "Africa/Dar_es_Salaam". Long enough for the
               longest real zone name rather than a guess. */
            $table->string('timezone', 64)->nullable()->after('preferred_language');
            /* A token string the frontend maps to a formatter, not a raw
               pattern: accepting arbitrary patterns from a client is how you
               end up formatting dates with user-supplied code. */
            $table->string('date_format', 20)->nullable()->after('timezone');
            $table->string('number_format', 20)->nullable()->after('date_format');
            /* light | dark | system. Stored so the choice follows the person
               between devices; the existing next-themes cookie still drives
               the actual switch, and is untouched. */
            $table->string('theme', 10)->nullable()->after('number_format');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['timezone', 'date_format', 'number_format', 'theme']);
        });
    }
};

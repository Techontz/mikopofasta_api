<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lookup lists behind the registration form's dropdowns.
 *
 * WHY TABLES AND NOT ENUMS. Every one of these was going to be a TypeScript
 * constant, and every one of them is a business decision the institution owns.
 * "Loan Type" today is Watumishi and Biashara; next quarter it is whatever the
 * product team invents. A frontend enum means a code change, a review, a build
 * and a deploy to add a row to a list — and it means the API and the UI can
 * disagree about what a valid value is, which is how a dropdown ends up
 * offering something the backend rejects.
 *
 * These are admin-managed master data: created, renamed, disabled and
 * soft-deleted from the Administration module, and read by the form at runtime.
 * The frontend knows none of the values in advance.
 *
 * SHARED SHAPE. All nine carry the same columns, deliberately, so one
 * controller, one policy, one resource and one admin screen serve all of them.
 * `code` is the stable machine value that data references; `name` is what the
 * officer reads and may be changed freely. Renaming "BINAFSI" to "Individual"
 * must not orphan every customer registered under it, which is exactly what
 * storing the label would do.
 *
 * `is_active` disables a list entry without deleting it: a loan type withdrawn
 * from sale still has to render on the customers who hold one. Deletion is
 * soft for the same reason.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const array TABLES = [
        'loan_types',
        'customer_types',
        'account_types',
        'work_types',
        'employment_types',
        'occupations',
        'banks',
        'mobile_money_providers',
        'marital_statuses',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
                /* Stable and unique: what customers reference. Never shown. */
                $blueprint->string('code', 40)->unique();
                /* What the officer reads. Renameable without consequence. */
                $blueprint->string('name', 120);
                $blueprint->string('description', 255)->nullable();
                /*
                 * The old system orders some of these lists by hand rather than
                 * alphabetically — the common choice sits first. Nullable so a
                 * list that does not care falls back to name order.
                 */
                $blueprint->unsignedSmallInteger('sort_order')->nullable();
                $blueprint->boolean('is_active')->default(true);
                $blueprint->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $blueprint->timestamps();
                $blueprint->softDeletes();

                $blueprint->index(['is_active', 'sort_order']);
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};

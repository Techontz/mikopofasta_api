<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Types gain a description, an activation switch and a display order.
 *
 * WHY. `customer_categories` is the Customer Type — the broad classification an
 * institution files a customer under, and the record that decides what the rest
 * of registration asks for. An administrator managing that list needs to be
 * able to retire one without destroying it, and to decide what order the
 * officer sees them in. Neither was representable: the table had no `is_active`
 * and no `sort_order`, so "deactivate" could only be done by deleting, and
 * deleting is refused the moment a customer is filed under it — correctly, and
 * that left no way to take a type out of use at all.
 *
 * `description` for the same reason: the admin screen shows one, and there was
 * nowhere to put it.
 *
 * DEACTIVATION IS THE POINT. A type with customers behind it must never be
 * deletable — their classification would vanish with it. Switching it off stops
 * it being offered to new registrations and leaves every existing customer
 * exactly as they are, which is what an institution actually wants when it
 * stops serving a group.
 *
 * ADDITIVE AND SAFE. Three nullable-or-defaulted columns. No row is inserted,
 * updated, deleted or re-keyed; `customers.customer_category_id`,
 * `category_product_eligibility`, the required documents and the dynamic
 * schemas are all untouched. Existing types default to ACTIVE, which is what
 * they were before this column existed — nothing an institution is using
 * silently disappears from the picker the moment this migration runs.
 *
 * NO BUSINESS DATA. This creates no customer type, and neither does any seeder
 * a production installation runs. A fresh install has none, and the Super
 * Administrator creates the institution's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_categories', function (Blueprint $table): void {
            $table->string('description', 255)->nullable()->after('code');

            /*
             * Default TRUE, deliberately: every type that already exists is in
             * use, and defaulting to false would take the whole list out of
             * registration on deploy.
             */
            $table->boolean('is_active')->default(true)->after('description');
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');

            /* The picker reads active types in the administrator's own order. */
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('customer_categories', function (Blueprint $table): void {
            $table->dropIndex(['is_active', 'sort_order']);
            $table->dropColumn(['description', 'is_active', 'sort_order']);
        });
    }
};

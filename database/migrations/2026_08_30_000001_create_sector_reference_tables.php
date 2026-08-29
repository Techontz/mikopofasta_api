<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a customer works, in two levels — the employer body, then the cadre.
 *
 * WHAT WAS MISSING. Registration asks a Public Servant which sector they serve
 * (TAMISEMI, for instance) and then which cadre within it (Teachers, Nurses).
 * Neither existed. `customer_categories.sector` looks like it might be the
 * place, but it is an enum of `employment|business|other` — an internal
 * grouping label for the wizard's headings, not an organisation anybody works
 * for. Overloading it would have meant one column answering two unrelated
 * questions.
 *
 * TWO TABLES, NOT A SELF-JOIN. A sector category belongs to exactly one
 * sector, and the form loads the second list only once the first is chosen —
 * the same cascade the address step already runs for region → district. A
 * parent_id on one table would model a tree we do not have and would let
 * somebody file a cadre under a cadre.
 *
 * THE SAME SHAPE AS THE OTHER TEN LISTS (see 2026_08_02 and 2026_08_15), so
 * both ride on the existing MasterDataModel, MasterDataController, policy,
 * resource and Administration screen. A new sector is a data change made by an
 * administrator, never a deployment.
 *
 * STRUCTURE ONLY — NO ROWS. Which bodies an institution lends against, and
 * which cadres sit inside them, is the institution's to decide and not this
 * application's to ship. A fresh install starts with both tables empty; the
 * registration form says so and names where they are added, and Administration
 * → Master Data → Sectors is where an administrator creates them.
 *
 * (Development and test databases get demonstration rows from
 * ReferenceDataSeeder, which production never runs.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sectors', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('sector_categories', function (Blueprint $table): void {
            $table->id();
            /* The owning sector. Cascading the delete would be wrong — a sector
               is soft-deleted, and its cadres must survive to keep the
               customers already filed under them readable. */
            $table->foreignId('sector_id')->constrained('sectors')->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            /* Unique WITHIN a sector, not globally: two employing bodies may
               each have an "Administration" cadre and they are not the same
               job. */
            $table->unique(['sector_id', 'code']);
            $table->index(['sector_id', 'is_active', 'sort_order']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('sector_categories');
        Schema::dropIfExists('sectors');
    }
};

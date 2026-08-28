<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
 * SEEDED ONLY WITH WHAT THE REQUIREMENT NAMES. TAMISEMI, Teachers and Nurses
 * are the examples written into the specification; they are here so the
 * cascade has something real to demonstrate on day one. Tanzania's full list
 * of employing bodies and cadres is not invented here — it is added through
 * Administration → Master Data, and the deployment note records that.
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

        $now = now();

        DB::table('sectors')->insert([
            'code' => 'TAMISEMI',
            'name' => 'TAMISEMI',
            'description' => "President's Office — Regional Administration and Local Government.",
            'sort_order' => 10,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $tamisemi = (int) DB::table('sectors')->where('code', 'TAMISEMI')->value('id');

        DB::table('sector_categories')->insert(array_map(
            static fn (array $r): array => [
                'sector_id' => $tamisemi,
                'code' => $r[0],
                'name' => $r[1],
                'description' => null,
                'sort_order' => $r[2],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                ['TEACHERS', 'Teachers', 10],
                ['NURSES', 'Nurses', 20],
            ],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('sector_categories');
        Schema::dropIfExists('sectors');
    }
};

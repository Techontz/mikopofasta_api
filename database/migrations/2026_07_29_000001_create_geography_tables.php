<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backend spec §2.2 — the Tanzanian administrative hierarchy:
     * region → district → ward → street.
     *
     * These are reference data, not business records, and the spec gives them
     * no `deleted_at` — unlike zones and branches, which do soft-delete. They
     * serve two purposes: structured customer addresses (§2.4 populates
     * `customers.region_id` … `street_id`), and the Regional Manager oversight
     * axis via `branches.region_id` (§12).
     *
     * Names are unique within their parent rather than globally: Tanzania has
     * more than one ward called "Mjimwema", but never two in the same district.
     */
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        Schema::create('districts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['region_id', 'name']);
        });

        Schema::create('wards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('district_id')->constrained('districts')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['district_id', 'name']);
        });

        Schema::create('streets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ward_id')->constrained('wards')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['ward_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streets');
        Schema::dropIfExists('wards');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('regions');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backend spec §2.2 — `zones`.
     *
     * Zones group branches for Zone Manager oversight and commission override
     * scoping (§12). They are a *separate axis* from regions: regions are
     * geographic and drive Regional Manager oversight, zones are a commission
     * grouping. The same branch is sliced both ways.
     *
     * `zone_manager_id` is nullable and nullOnDelete rather than restrict: a
     * zone outliving its manager's account is a normal state (the seeded East
     * Zone has no manager at all), and blocking a user deletion because they
     * happen to head a zone would be the wrong trade.
     */
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->foreignId('zone_manager_id')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->softDeletes();

            $table->index('zone_manager_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};

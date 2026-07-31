<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Completes `users.branch_id` / `zone_id` / `region_id` from backend
     * spec §2.1.
     *
     * These columns were created without constraints in Phase 2 because the
     * organization tables did not exist yet; the login response already had to
     * carry the user's scope. This migration closes that gap and is the last
     * of the Phase 2 placeholders.
     *
     * RESTRICT on delete throughout, per the schema-wide rule in spec §2:
     * a branch, zone or region that still has staff assigned must be emptied
     * before it can be removed. The delete actions surface that as a clean
     * 409 rather than letting the database raise it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign('zone_id')->references('id')->on('zones')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign('region_id')->references('id')->on('regions')->restrictOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['branch_id']);
            $table->dropForeign(['zone_id']);
            $table->dropForeign(['region_id']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Completes `users.role_id` → `roles.id` from backend spec §2.1.
     *
     * This is a separate migration only because `roles` is created by the
     * Spatie permission migration, which sorts after the users table.
     *
     * RESTRICT on delete, per the schema-wide rule in spec §2: nothing here is
     * ever hard-deleted through a cascade. A role that still has users must be
     * emptied before it can be removed.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('role_id')
                ->references('id')
                ->on('roles')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['role_id']);
        });
    }
};

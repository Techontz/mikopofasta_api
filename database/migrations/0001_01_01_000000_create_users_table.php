<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backend spec §2.1 — `users`.
     *
     * Two forward references cannot be satisfied at this point in the
     * migration order and are added later rather than here:
     *
     *  - `role_id` → roles.id     the Spatie permission tables are created by
     *                             a later migration; the FK is added by
     *                             add_role_foreign_key_to_users_table.
     *  - `branch_id` / `zone_id` / `region_id` → those tables belong to the
     *                             Organization module (Phase 3). The columns
     *                             exist now because the login response must
     *                             carry the user's scope, but the FK
     *                             constraints are deferred to that phase.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('phone', 20)->unique();
            $table->string('email', 150)->nullable()->unique();
            $table->string('password');

            $table->foreignId('role_id');

            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('zone_id')->nullable();
            $table->unsignedBigInteger('region_id')->nullable();

            $table->enum('status', UserStatus::values())->default(UserStatus::Active->value);
            $table->timestamp('last_login_at')->nullable();
            $table->foreignId('created_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('role_id');
            $table->index('branch_id');
            $table->index('zone_id');
            $table->index('region_id');
            $table->index('status');
        });

        Schema::table('users', function (Blueprint $table): void {
            // Self-reference: who provisioned this account.
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        /*
         * Keyed by email because Laravel's password broker is email-based.
         * Note that authentication itself is by PHONE (the frontend login form
         * posts phone + password) and `users.email` is nullable — so a user
         * without an email address cannot use the reset flow. See the Phase 2
         * notes in README.md.
         */
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};

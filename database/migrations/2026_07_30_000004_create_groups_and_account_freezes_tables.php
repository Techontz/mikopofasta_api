<?php

declare(strict_types=1);

use App\Enums\ActiveStatus;
use App\Enums\FreezableType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `groups` / `group_members` (spec §2.4) and `account_freezes` (spec §2.1).
     *
     * Groups are backend support only in this phase. The tables, models and
     * seed exist because §2.4 defines them and the customer profile shows
     * group membership read-only, but the frontend has no group management
     * screens — it is item 1 on the readiness report's known-gaps list. No
     * group CRUD endpoints are exposed here; adding them would be inventing a
     * module the contract does not describe.
     *
     * `account_freezes` is polymorphic over customers, loans and staff. Only
     * the customer case is reachable in Phase 4; loans and staff arrive with
     * their own modules. It is append-and-close rather than mutable: freezing
     * inserts a row, unfreezing stamps `unfrozen_at` on the open one, so the
     * full freeze history survives.
     */
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('leader_customer_id')->nullable()
                ->constrained('customers')->nullOnDelete()->cascadeOnUpdate();
            $table->enum('status', ActiveStatus::values())->default(ActiveStatus::Active->value);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'name']);
            $table->index('status');
        });

        Schema::create('group_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete()->cascadeOnUpdate();
            $table->date('joined_at');
            $table->enum('status', ['active', 'left'])->default('active');
            $table->timestamps();

            $table->unique(['group_id', 'customer_id']);
            $table->index('customer_id');
        });

        Schema::create('account_freezes', function (Blueprint $table): void {
            $table->id();

            $table->enum('freezable_type', FreezableType::values());
            $table->unsignedBigInteger('freezable_id');

            $table->string('reason', 255);

            $table->foreignId('frozen_by')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->timestamp('frozen_at');

            $table->foreignId('unfrozen_by')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->timestamp('unfrozen_at')->nullable();

            $table->timestamps();

            $table->index(['freezable_type', 'freezable_id']);

            // The open-freeze lookup ("is this account currently frozen?").
            $table->index(['freezable_type', 'freezable_id', 'unfrozen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_freezes');
        Schema::dropIfExists('group_members');
        Schema::dropIfExists('groups');
    }
};

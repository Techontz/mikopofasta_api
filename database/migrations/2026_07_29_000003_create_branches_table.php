<?php

declare(strict_types=1);

use App\Domain\Organization\Enums\BranchType;
use App\Enums\ActiveStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backend spec §2.2 — `branches`.
     *
     * HQ is a branch row flagged `is_head_office`, not a separate table
     * (§12 Decision 2), so it reuses the same ledger machinery, expense
     * tagging and staff assignment as any other branch.
     *
     * "At most one row TRUE" cannot be expressed as a plain MySQL constraint,
     * and the spec says so explicitly — it is enforced in the application
     * layer (SetHeadOfficeAction, which demotes the incumbent inside the same
     * transaction).
     *
     * `parent_branch_id` self-references so a sub-branch rolls up into a main
     * branch for reporting. Cycle prevention is an application concern; a
     * cycle here would make hierarchy traversal non-terminating.
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150)->unique();

            $table->foreignId('region_id')->nullable()->constrained('regions')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('zone_id')->nullable()->constrained('zones')->restrictOnDelete()->cascadeOnUpdate();

            $table->string('phone', 20);
            $table->enum('type', BranchType::values())->default(BranchType::Main->value);

            $table->foreignId('parent_branch_id')->nullable()->constrained('branches')->restrictOnDelete()->cascadeOnUpdate();

            $table->boolean('is_head_office')->default(false);
            $table->enum('status', ActiveStatus::values())->default(ActiveStatus::Active->value);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();

            $table->timestamps();
            $table->softDeletes();

            $table->index('region_id');
            $table->index('zone_id');
            $table->index('status');
            $table->index('is_head_office');
            $table->index('parent_branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};

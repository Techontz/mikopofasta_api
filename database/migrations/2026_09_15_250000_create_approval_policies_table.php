<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C6 company approval policy: one row per company and two-step workflow (App\Models\ApprovalPolicy::WORKFLOWS). A missing row
 * means the defaults — approval required, self-approval NOT allowed. `allow_self_approval` lets an initiator who ALSO holds the
 * explicit `approvals.self_approve` permission approve their own item in that workflow. `requires_approval` is stored for
 * future use only and is ignored (maker/checker stays mandatory).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('workflow', 60);
            $table->boolean('requires_approval')->default(true);
            $table->boolean('allow_self_approval')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'workflow']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_policies');
    }
};

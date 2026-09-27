<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credit assessment snapshots (§36 / §37): the recommendation a Credit Officer was actually shown, frozen with the
     * factors, weights and evidence that produced it. Additive and advisory only — nothing here changes a loan status,
     * an amount or an approval. A loan may carry many snapshots (re-assessed after new evidence); the latest one is the
     * live recommendation and the older rows stay for audit.
     */
    public function up(): void
    {
        Schema::create('loan_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->decimal('requested_amount', 15, 2);
            $table->decimal('recommended_amount', 15, 2);
            $table->decimal('recommended_ratio', 6, 4)->default(0);
            $table->decimal('score', 6, 2)->default(0);
            $table->string('risk_band', 20);
            $table->json('factors');
            $table->json('excluded_factors');
            $table->json('overrides')->nullable();
            $table->json('limits')->nullable();
            $table->json('steps')->nullable();
            $table->text('explanation');
            $table->string('engine_version', 20);
            $table->timestamp('assessed_at');
            $table->timestamps();

            $table->index(['loan_id', 'assessed_at']);
            $table->index(['customer_id', 'assessed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_assessments');
    }
};

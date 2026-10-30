<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duplicate customer prevention (enhancement §2.1): a registration whose bank account, check number or three names match
 * an existing customer of any branch is not created; it waits here, `pending`, until an employee holding
 * customers.approve releases it (registered, the authorized exception) or rejects it as a confirmed duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_duplicate_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->json('matches');
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reason', 1000)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_duplicate_reviews');
    }
};

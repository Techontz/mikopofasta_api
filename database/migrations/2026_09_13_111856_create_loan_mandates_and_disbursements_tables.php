<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bank e-mandates (mandate products) and Vodacom disbursement batches — one row per attempt,
     * each with its own batch id (Documents: "Old Batch: VODA123 / New Batch: VODA124").
     */
    public function up(): void
    {
        Schema::create('loan_mandates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('bank_name');
            $table->string('account_number', 50);
            $table->string('account_name');
            $table->string('mandate_reference')->nullable();
            $table->string('status', 20);
            $table->unsignedTinyInteger('otp_attempts')->default(0);
            $table->string('failure_reason')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('loan_disbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->string('batch_id', 40)->unique();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->string('channel', 20)->default('vodacom');
            $table->string('phone', 20)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('status', 20);
            $table->string('provider_reference')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('callback_payload')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_disbursements');
        Schema::dropIfExists('loan_mandates');
    }
};

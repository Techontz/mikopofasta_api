<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C6 maker/checker for PAID share issuances: the issuance is requested (pending — no capital row, no journal, no share
 * transaction, ownership unchanged) and posted only when a different authorised user approves it; a rejection keeps the row
 * with the reason. Linked-contribution and bonus issuances post no journal and stay single-step. Existing share transactions
 * are untouched (they have no request).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_issuance_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('share_holder_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shares');
            $table->decimal('price_per_share', 20, 2);
            $table->decimal('total_amount', 24, 2);
            $table->date('issue_date');
            $table->string('pay_method', 10);
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('receipt_number')->nullable();
            $table->string('cheque_number')->nullable();
            $table->text('notes')->nullable();
            $table->string('document_path')->nullable();
            $table->string('document_name', 191)->nullable();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('requested_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->foreignId('share_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('capital_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_issuance_requests');
    }
};

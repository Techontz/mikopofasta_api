<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PAY ALL OUTSTANDING: one batch per submission groups the per-shareholder dividend payments it posted (each payment
     * keeps its own journal entry). The batch idempotency key makes a repeated submission return the original batch.
     */
    public function up(): void
    {
        Schema::create('dividend_payment_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dividend_declaration_id')->constrained()->restrictOnDelete();
            $table->string('batch_reference', 40)->nullable()->unique();
            $table->string('pay_method', 10);
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->unsignedInteger('payments_count')->default(0);
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->foreignId('paid_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('paid_at');
            $table->timestamps();
        });

        Schema::table('dividend_payments', function (Blueprint $table) {
            $table->foreignId('dividend_payment_batch_id')->nullable()->after('share_holder_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dividend_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dividend_payment_batch_id');
        });

        Schema::dropIfExists('dividend_payment_batches');
    }
};

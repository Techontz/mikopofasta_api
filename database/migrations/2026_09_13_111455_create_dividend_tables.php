<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monthly profit distribution (Documents: ACCOUNT OVERVIEW "Dividend Account" — Profit → Dividend,
     * 70% → Principal (reinvestment), 30% → shareholders split by share percentage).
     */
    public function up(): void
    {
        Schema::create('dividend_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('period');
            $table->decimal('profit_amount', 15, 2);
            $table->decimal('reinvest_percent', 5, 2);
            $table->decimal('reinvest_amount', 15, 2);
            $table->decimal('dividend_percent', 5, 2);
            $table->decimal('dividend_amount', 15, 2);
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('declared_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'period']);
        });

        Schema::create('dividend_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dividend_declaration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('share_holder_id')->constrained()->cascadeOnDelete();
            $table->decimal('share_percent', 7, 4);
            $table->decimal('amount', 15, 2);
            $table->string('status')->default('pending');
            $table->string('pay_method')->nullable();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dividend_allocations');
        Schema::dropIfExists('dividend_declarations');
    }
};

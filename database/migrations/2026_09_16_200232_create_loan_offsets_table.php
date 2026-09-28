<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offset records (specification §13 and §14): old loan debt settled internally out of a new top-up loan, because the
 * customer never brought that money in cash.
 *
 * The settlement itself is an ordinary repayment posted component by component; this table is the tracking attribute the
 * spec asks for — which old loan, which new loan, how much of it was principal, penalty, interest or salary advance, and
 * how much cash the customer actually received. It is what lets the offset be excluded from the commission base and added
 * back before dividend and reinvestment (§15).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_offsets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('old_loan_id')->constrained('loans')->cascadeOnDelete();
            $table->foreignId('new_loan_id')->constrained('loans')->cascadeOnDelete();
            $table->foreignId('loan_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('principal_amount', 15, 2)->default(0);
            $table->decimal('penalty_amount', 15, 2)->default(0);
            $table->decimal('interest_amount', 15, 2)->default(0);
            $table->decimal('salary_advance_amount', 15, 2)->default(0);
            $table->decimal('insurance_amount', 15, 2)->default(0);
            $table->decimal('cash_disbursed', 15, 2)->default(0);
            $table->date('settled_on');
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->unique('new_loan_id');
            $table->index(['company_id', 'settled_on']);
            $table->index(['branch_id', 'settled_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_offsets');
    }
};

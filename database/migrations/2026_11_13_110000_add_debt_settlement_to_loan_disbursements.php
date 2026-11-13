<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Debt clearance on a new loan's disbursement: the batch keeps the debts it netted (plan) and, once paid, what was settled;
 * Salary Advance and penalty payments made out of a loan's proceeds name that loan; a loan offset may clear debts other
 * than a topped-up loan (old_loan_id empty).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_disbursements', function (Blueprint $table): void {
            $table->json('debt_settlement')->nullable()->after('amount');
        });
        Schema::table('salary_advance_payments', function (Blueprint $table): void {
            $table->foreignId('settled_by_loan_id')->nullable()->after('paid_on')->constrained('loans')->nullOnDelete();
        });
        Schema::table('penalty_payments', function (Blueprint $table): void {
            $table->foreignId('settled_by_loan_id')->nullable()->after('paid_on')->constrained('loans')->nullOnDelete();
        });
        Schema::table('loan_offsets', function (Blueprint $table): void {
            $table->foreignId('old_loan_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('penalty_payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('settled_by_loan_id'));
        Schema::table('salary_advance_payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('settled_by_loan_id'));
        Schema::table('loan_disbursements', fn (Blueprint $table) => $table->dropColumn('debt_settlement'));
    }
};

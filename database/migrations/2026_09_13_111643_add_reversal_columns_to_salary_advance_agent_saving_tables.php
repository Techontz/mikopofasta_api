<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documents (ACCOUNT OVERVIEW): "no delete — reversal only". Money records of the salary advance,
 * agent and insurance saving modules keep a reversal marker instead of being deleted. Savings also
 * record how a withdrawal was used (live "withdrawal by": TAKEN / CLEAR LOAN) and the loan it cleared.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['salary_advances', 'agent_transactions', 'savings'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->timestamp('reversed_at')->nullable();
                $table->string('reversal_reason')->nullable();
                $table->foreignId('reversed_by')->nullable()->constrained('employees')->nullOnDelete();
            });
        }

        Schema::table('salary_advances', function (Blueprint $table): void {
            $table->foreignId('employee_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
        });

        Schema::table('savings', function (Blueprint $table): void {
            $table->string('withdrawal_type')->nullable()->after('type');
            $table->foreignId('loan_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->after('loan_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('savings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('loan_id');
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn('withdrawal_type');
        });

        Schema::table('salary_advances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('employee_id');
        });

        foreach (['salary_advances', 'agent_transactions', 'savings'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('reversed_by');
                $table->dropColumn(['reversed_at', 'reversal_reason']);
            });
        }
    }
};

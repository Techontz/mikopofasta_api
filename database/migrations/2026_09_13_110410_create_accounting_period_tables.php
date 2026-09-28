<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Month-end results per branch (Documents: ACCOUNT OVERVIEW "Month end process",
     * STAFF COMMISSION "distributable profit"). Written by the accounting period close,
     * read by the commission engine and financial reports.
     */
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('open');
            $table->foreignId('closed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'period_start']);
        });

        Schema::create('branch_period_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accounting_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->decimal('interest_income', 15, 2)->default(0);
            $table->decimal('fee_income', 15, 2)->default(0);
            $table->decimal('penalty_income', 15, 2)->default(0);
            $table->decimal('recovery_income', 15, 2)->default(0);
            $table->decimal('total_income', 15, 2)->default(0);
            $table->decimal('expenses', 15, 2)->default(0);
            $table->decimal('gross_profit', 15, 2)->default(0);
            $table->decimal('loss_brought_forward', 15, 2)->default(0);
            $table->decimal('net_profit', 15, 2)->default(0);
            $table->decimal('loss_carried_forward', 15, 2)->default(0);
            $table->decimal('hq_hold_percent', 5, 2)->default(2);
            $table->decimal('hq_hold_amount', 15, 2)->default(0);
            $table->decimal('distributable_profit', 15, 2)->default(0);
            $table->boolean('commission_eligible')->default(false);
            $table->timestamps();
            $table->unique(['accounting_period_id', 'branch_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_period_results');
        Schema::dropIfExists('accounting_periods');
    }
};

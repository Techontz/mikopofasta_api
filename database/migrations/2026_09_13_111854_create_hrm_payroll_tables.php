<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HRM modifications from the Documents (STAFF COMMISSION overview, handwritten HR notes):
     * salary structures, payroll workflow (HR approves → Finance pays), commission engine,
     * staff fund, finance disbursement of staff loans/advances, attendance and performance.
     */
    public function up(): void
    {
        Schema::table('employee_salaries', function (Blueprint $table) {
            $table->string('salary_type')->default('branch')->after('salary');
            $table->boolean('commission_eligible')->default(true)->after('salary_type');
            $table->string('payment_method')->default('bank')->after('commission_eligible');
        });

        Schema::create('hrm_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('commission_pool_percent', 5, 2)->default(10);
            $table->decimal('zone_override_percent', 5, 2)->default(5);
            $table->decimal('staff_fund_percent', 5, 2)->default(20);
            $table->string('work_start_time', 5)->default('08:00');
            $table->timestamps();
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('period');
            $table->string('status')->default('draft');
            $table->string('commission_status')->nullable();
            $table->decimal('total_gross', 15, 2)->default(0);
            $table->decimal('total_deductions', 15, 2)->default(0);
            $table->decimal('total_net', 15, 2)->default(0);
            $table->foreignId('prepared_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'period']);
        });

        Schema::table('salary_payments', function (Blueprint $table) {
            $table->foreignId('payroll_run_id')->nullable()->after('employee_id')->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('payroll_run_id')->constrained()->nullOnDelete();
            $table->string('salary_type')->nullable()->after('branch_id');
            $table->decimal('commission', 15, 2)->default(0)->after('salary');
            $table->decimal('staff_fund', 15, 2)->default(0)->after('allowance');
        });

        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('salary_type');
            $table->decimal('base_salary', 15, 2)->default(0);
            $table->decimal('commission', 15, 2)->default(0);
            $table->decimal('allowance', 15, 2)->default(0);
            $table->decimal('gross', 15, 2)->default(0);
            $table->decimal('staff_fund', 15, 2)->default(0);
            $table->decimal('salary_advance', 15, 2)->default(0);
            $table->decimal('deduction', 15, 2)->default(0);
            $table->decimal('loan_restoration', 15, 2)->default(0);
            $table->decimal('total_deductions', 15, 2)->default(0);
            $table->decimal('take_home', 15, 2)->default(0);
            $table->string('phone')->nullable();
            $table->string('account_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('payment_method')->nullable();
            $table->foreignId('salary_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::create('commission_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accounting_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->decimal('distributable_profit', 15, 2)->default(0);
            $table->decimal('pool_percent', 5, 2)->default(0);
            $table->decimal('pool_amount', 15, 2)->default(0);
            $table->decimal('base_salary', 15, 2)->default(0);
            $table->decimal('total_salary', 15, 2)->default(0);
            $table->decimal('share_percent', 8, 4)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->foreignId('payroll_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['accounting_period_id', 'employee_id', 'kind'], 'commission_alloc_period_employee_kind_unique');
        });

        Schema::table('staff_salary_advances', function (Blueprint $table) {
            $table->decimal('recovered_amount', 15, 2)->default(0)->after('fee');
            $table->string('source_account')->nullable()->after('recovered_amount');
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('disbursed_by')->nullable()->after('approved_at')->constrained('employees')->nullOnDelete();
            $table->timestamp('disbursed_at')->nullable()->after('disbursed_by');
        });

        Schema::table('staff_loans', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('disbursed_by')->nullable()->after('approved_at')->constrained('employees')->nullOnDelete();
            $table->timestamp('disbursed_at')->nullable()->after('disbursed_by');
        });

        Schema::table('leaves', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('employees')->nullOnDelete();
        });

        Schema::create('staff_fund_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->text('reason');
            $table->foreignId('recorded_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();
            $table->string('status')->default('present');
            $table->string('remarks')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'date']);
            $table->index(['company_id', 'date']);
        });

        Schema::create('staff_performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('period');
            $table->text('targets')->nullable();
            $table->string('discipline')->nullable();
            $table->unsignedTinyInteger('rating');
            $table->text('remarks')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_performance_reviews');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('staff_fund_withdrawals');

        Schema::table('leaves', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
        });
        Schema::table('staff_loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('disbursed_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approved_at', 'disbursed_at']);
        });
        Schema::table('staff_salary_advances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('disbursed_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['recovered_amount', 'source_account', 'approved_at', 'disbursed_at']);
        });

        Schema::dropIfExists('commission_allocations');
        Schema::dropIfExists('payroll_items');

        Schema::table('salary_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payroll_run_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['salary_type', 'commission', 'staff_fund']);
        });

        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('hrm_settings');

        Schema::table('employee_salaries', function (Blueprint $table) {
            $table->dropColumn(['salary_type', 'commission_eligible', 'payment_method']);
        });
    }
};

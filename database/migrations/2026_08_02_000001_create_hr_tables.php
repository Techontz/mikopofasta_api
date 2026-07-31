<?php

declare(strict_types=1);

use App\Domain\Hr\Enums\AllowanceType;
use App\Domain\Hr\Enums\DeductionType;
use App\Domain\Hr\Enums\EmploymentStatus;
use App\Domain\Hr\Enums\PayrollRunStatus;
use App\Domain\Hr\Enums\PerformanceRating;
use App\Domain\Hr\Enums\StaffAdvanceStatus;
use App\Domain\Hr\Enums\StaffLoanStatus;
use App\Domain\Hr\Enums\StaffPaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backend spec §2.9 — HR, payroll and commission.
     *
     * §11 is explicit that staff need no parallel ledger machinery: "no new
     * physical tables needed, staff_profile_id becomes a filterable dimension
     * on journal_entry_lines (Staff Control / Staff Loan / Staff Advance /
     * Staff Deductions are views, not tables)". That dimension column has
     * existed since Phase 6 without a foreign key, because the table it points
     * at did not exist yet. This migration finally constrains it.
     *
     * Money columns are DECIMAL(18,2) and percentages DECIMAL(6,3), matching
     * every other table — so `App\Support\Money` and `Percentage` round-trip
     * without loss and no payroll figure is ever a float.
     */
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table): void {
            $table->id();

            // One profile per user, and §11 creates the pair together: "HR
            // registers staff (users + staff_profiles created together)".
            $table->foreignId('user_id')->unique()->constrained('users')
                ->restrictOnDelete()->cascadeOnUpdate();

            $table->string('employee_number', 30)->unique();

            $table->foreignId('branch_id')->nullable()->constrained('branches')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('zone_id')->nullable()->constrained('zones')
                ->restrictOnDelete()->cascadeOnUpdate();

            $table->decimal('base_salary', 18, 2);
            $table->boolean('commission_eligible')->default(false);
            $table->enum('payment_method', StaffPaymentMethod::values())
                ->default(StaffPaymentMethod::Bank->value);
            $table->enum('employment_status', EmploymentStatus::values())
                ->default(EmploymentStatus::Active->value);
            $table->date('hired_at');

            $table->timestamps();
            $table->softDeletes();

            $table->index('branch_id');
            $table->index('zone_id');
            $table->index('employment_status');
        });

        Schema::create('staff_bank_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('bank_name', 100);
            $table->string('account_number', 50);
            $table->timestamps();

            $table->index('staff_profile_id');
        });

        /*
         * The staff_profile_id dimension gets its foreign key now that there
         * is something to point at. Journal lines are immutable and never
         * deleted, so RESTRICT is the only coherent rule: a staff profile with
         * ledger history cannot be removed from under it.
         */
        Schema::table('journal_entry_lines', function (Blueprint $table): void {
            $table->foreign('staff_profile_id')
                ->references('id')->on('staff_profiles')
                ->restrictOnDelete()->cascadeOnUpdate();
        });

        Schema::create('payroll_runs', function (Blueprint $table): void {
            $table->id();

            // One run per period, enforced by the database rather than by a
            // check in the action — a second June payroll would pay everyone
            // twice.
            $table->string('period', 7)->unique();

            $table->enum('status', PayrollRunStatus::values())->default(PayrollRunStatus::Draft->value);
            $table->foreignId('generated_by')->constrained('users')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('payroll_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')
                ->restrictOnDelete()->cascadeOnUpdate();

            $table->decimal('base_salary', 18, 2);
            $table->decimal('commission_amount', 18, 2)->default(0);
            $table->decimal('allowances_total', 18, 2)->default(0);
            $table->decimal('deductions_total', 18, 2)->default(0);
            $table->decimal('net_salary', 18, 2);

            // Null until Finance finalizes — a draft run has posted nothing.
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete()->cascadeOnUpdate();

            $table->timestamps();

            $table->unique(['payroll_run_id', 'staff_profile_id']);
        });

        Schema::create('allowances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_line_id')->constrained('payroll_lines')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->enum('type', AllowanceType::values());
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->index('payroll_line_id');
        });

        Schema::create('deductions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_line_id')->constrained('payroll_lines')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->enum('type', DeductionType::values());
            $table->decimal('amount', 18, 2);

            /*
             * Points at staff_loans.id or staff_advances.id depending on
             * `type` — §2.9 defines it that way, so it is deliberately not a
             * foreign key. Which table it refers to is carried by `type`.
             */
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->timestamps();

            $table->index('payroll_line_id');
        });

        Schema::create('commission_pools', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->string('period', 7);

            // Signed: a branch in loss has a negative profit and a negative
            // distributable, and §11's hard rule reads off that sign.
            $table->decimal('branch_profit', 18, 2);
            $table->decimal('loss_carry_forward', 18, 2)->default(0);
            $table->decimal('hq_hold_amount', 18, 2)->default(0);
            $table->decimal('distributable_profit', 18, 2);
            $table->decimal('pool_percentage', 6, 3);
            $table->decimal('pool_amount', 18, 2)->default(0);

            $table->timestamps();

            $table->unique(['branch_id', 'period']);
        });

        Schema::create('commission_distributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commission_pool_id')->constrained('commission_pools')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->decimal('share_amount', 18, 2);
            $table->timestamps();

            // Named explicitly: the auto-generated name exceeds MySQL's
            // 64-character identifier limit.
            $table->unique(['commission_pool_id', 'staff_profile_id'], 'commission_distributions_pool_staff_unique');
        });

        Schema::create('zone_commission_distributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('zone_id')->constrained('zones')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->string('period', 7);
            $table->decimal('total_pool_base', 18, 2);
            $table->decimal('override_percentage', 6, 3);
            $table->decimal('override_amount', 18, 2);

            /*
             * §2.9 types this NN, but the override is expensed as part of the
             * zone manager's payroll recognition entry and that entry does not
             * exist until Finance finalizes the run. Nullable, therefore, and
             * populated at finalization — the alternative would be a second
             * entry for money that has already been posted once.
             */
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete()->cascadeOnUpdate();

            $table->timestamps();

            $table->unique(['zone_id', 'period']);
        });

        Schema::create('staff_loans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->decimal('amount', 18, 2);
            $table->enum('status', StaffLoanStatus::values())->default(StaffLoanStatus::Active->value);
            $table->date('disbursed_at');
            $table->foreignId('journal_entry_id')->constrained('journal_entries')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->timestamps();

            $table->index(['staff_profile_id', 'status']);
        });

        Schema::create('staff_advances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->decimal('amount', 18, 2);
            $table->enum('status', StaffAdvanceStatus::values())->default(StaffAdvanceStatus::Requested->value);

            $table->timestamp('requested_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')
                ->nullOnDelete()->cascadeOnUpdate();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('disbursed_at')->nullable();

            // Null until Finance disburses — an approved advance has moved no
            // money, so there is nothing to record (§11).
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete()->cascadeOnUpdate();

            $table->timestamps();

            $table->index(['staff_profile_id', 'status']);
        });

        Schema::create('staff_performance_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('period', 7);

            // Free-form by design (§2.9): the metrics a manager reviews differ
            // by role, and pinning them to columns would freeze that.
            $table->json('targets_json');
            $table->json('achieved_json');

            $table->enum('rating', PerformanceRating::values())->nullable();
            $table->foreignId('recorded_by')->constrained('users')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->timestamps();

            $table->unique(['staff_profile_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table): void {
            $table->dropForeign(['staff_profile_id']);
        });

        Schema::dropIfExists('staff_performance_records');
        Schema::dropIfExists('staff_advances');
        Schema::dropIfExists('staff_loans');
        Schema::dropIfExists('zone_commission_distributions');
        Schema::dropIfExists('commission_distributions');
        Schema::dropIfExists('commission_pools');
        Schema::dropIfExists('deductions');
        Schema::dropIfExists('allowances');
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('staff_bank_details');
        Schema::dropIfExists('staff_profiles');
    }
};

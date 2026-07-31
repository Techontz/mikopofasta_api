<?php

declare(strict_types=1);

use App\Domain\Loans\Enums\DisbursementChannel;
use App\Domain\Loans\Enums\DisbursementStatus;
use App\Domain\Loans\Enums\EMandateStatus;
use App\Domain\Loans\Enums\LoanScheduleStatus;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Enums\TelcoVerificationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backend spec §2.5 — the loan aggregate and everything that hangs off it.
     */
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table): void {
            $table->id();
            $table->string('loan_number', 30)->unique();

            $table->foreignId('customer_id')->constrained('customers')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('loan_product_id')->constrained('loan_products')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('repayment_schedule_id')->constrained('repayment_schedules')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('group_id')->nullable()->constrained('groups')
                ->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('branch_id')->constrained('branches')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('officer_id')->constrained('users')
                ->restrictOnDelete()->cascadeOnUpdate();

            $table->decimal('principal_amount', 18, 2);

            /*
             * Snapshots, taken at application time (§6). An in-flight loan is
             * immune to a mid-term product edit: changing a product's rate
             * must never silently rewrite the terms of a loan already agreed
             * with a customer.
             *
             * penalty_rate_snapshot is DECIMAL(18,3) for the same reason as
             * loan_products.penalty_rate — see OSC-2 in that migration.
             */
            $table->decimal('interest_rate_snapshot', 6, 3);
            $table->decimal('penalty_rate_snapshot', 18, 3);
            $table->unsignedInteger('tenure_days');
            $table->boolean('requires_mandate_snapshot');

            $table->enum('status', LoanStatus::values())->default(LoanStatus::Draft->value);

            $table->date('disbursement_date')->nullable();
            $table->date('expected_completion_date')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')
                ->nullOnDelete()->cascadeOnUpdate();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejected_reason', 255)->nullable();
            $table->timestamp('closed_at')->nullable();

            // Post-closure cooldown on the customer (§6), stored on the loan
            // that started it.
            $table->date('frozen_until')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')
                ->nullOnDelete()->cascadeOnUpdate();

            $table->timestamps();

            /*
             * Soft delete only for a genuine data-entry mistake before
             * approval; never after disbursement (§2.5, enforced in the
             * application layer).
             */
            $table->softDeletes();

            $table->index('customer_id');
            $table->index('branch_id');
            $table->index('status');
            $table->index('loan_product_id');
            $table->index('officer_id');
        });

        /*
         * "Kila action recorded" (§10). Every transition writes a row, so the
         * loan's whole history is reconstructable without reading audit logs.
         */
        Schema::create('loan_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete()->cascadeOnUpdate();
            $table->enum('from_status', LoanStatus::values())->nullable();
            $table->enum('to_status', LoanStatus::values());
            $table->foreignId('changed_by')->nullable()->constrained('users')
                ->nullOnDelete()->cascadeOnUpdate();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('loan_id');
        });

        /*
         * The installment plan. NO soft delete: a schedule row is money owed,
         * and §2 forbids deleting anything ledger-adjacent — corrections go
         * through a new row or a reversal, never a disappearance.
         */
        Schema::create('loan_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete()->cascadeOnUpdate();
            $table->unsignedInteger('installment_number');
            $table->date('due_date');

            $table->decimal('principal_due', 18, 2);
            $table->decimal('interest_due', 18, 2);
            $table->decimal('penalty_due', 18, 2)->default(0);

            $table->decimal('principal_paid', 18, 2)->default(0);
            $table->decimal('interest_paid', 18, 2)->default(0);
            $table->decimal('penalty_paid', 18, 2)->default(0);

            $table->enum('status', LoanScheduleStatus::values())
                ->default(LoanScheduleStatus::Pending->value);

            $table->timestamps();

            $table->unique(['loan_id', 'installment_number']);
            $table->index('due_date');
            $table->index('status');
        });

        Schema::create('e_mandates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('bank_name', 100);
            $table->string('otp_reference', 60)->nullable();
            $table->enum('status', EMandateStatus::values())->default(EMandateStatus::PendingOtp->value);
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index('loan_id');
        });

        Schema::create('telco_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('provider', 30)->default('vodacom');
            $table->json('request_payload');
            $table->json('response_payload')->nullable();
            $table->enum('status', TelcoVerificationStatus::values())
                ->default(TelcoVerificationStatus::Pending->value);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index('loan_id');
        });

        /*
         * One row per disbursement attempt. A retry never mutates the previous
         * batch — it inserts a new one with attempt_number+1 and a fresh
         * reference (§6), so the history of failed attempts survives.
         *
         * No soft delete: this is money-movement evidence.
         */
        Schema::create('disbursement_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('batch_reference', 40)->unique();
            $table->unsignedInteger('attempt_number')->default(1);
            $table->enum('channel', DisbursementChannel::values())
                ->default(DisbursementChannel::Vodacom->value);
            $table->enum('status', DisbursementStatus::values())
                ->default(DisbursementStatus::Pending->value);
            $table->string('failure_reason', 255)->nullable();
            $table->foreignId('requested_by')->constrained('users')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('loan_id');
            $table->index('status');
        });

        Schema::create('loan_topups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('original_loan_id')->constrained('loans')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('new_loan_id')->constrained('loans')
                ->restrictOnDelete()->cascadeOnUpdate();

            // Why the top-up was allowed, frozen at the moment it was granted.
            $table->json('eligibility_snapshot');

            $table->timestamp('created_at')->useCurrent();

            $table->index('original_loan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_topups');
        Schema::dropIfExists('disbursement_batches');
        Schema::dropIfExists('telco_verifications');
        Schema::dropIfExists('e_mandates');
        Schema::dropIfExists('loan_schedules');
        Schema::dropIfExists('loan_status_history');
        Schema::dropIfExists('loans');
    }
};

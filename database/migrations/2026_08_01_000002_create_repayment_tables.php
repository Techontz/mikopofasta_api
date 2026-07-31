<?php

declare(strict_types=1);

use App\Domain\Repayments\Enums\CashDepositStatus;
use App\Domain\Repayments\Enums\PaymentChannel;
use App\Domain\Repayments\Enums\PaymentStatus;
use App\Domain\Repayments\Enums\SuspenseStatus;
use App\Domain\Repayments\Enums\TriggeredBy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backend spec §2.6 — repayments and collections.
     *
     * `payments` has no `deleted_at`: §2 lists it among the tables where
     * deletion is architecturally impossible once confirmed. A payment that
     * should not have happened is reversed, not removed.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('payment_reference', 50)->unique();

            // Null until matched or allocated — an unmatched payment is still
            // a payment, and §7 is emphatic that it is never dropped.
            $table->foreignId('loan_id')->nullable()->constrained('loans')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('customer_id')->nullable()->constrained('customers')
                ->restrictOnDelete()->cascadeOnUpdate();

            $table->decimal('amount', 18, 2);
            $table->enum('channel', PaymentChannel::values());

            /*
             * Unique at the database level — half of §7's "belt and braces"
             * against duplicate provider callbacks (the other half is the
             * Idempotency-Key on the webhook).
             */
            $table->string('transaction_id', 80)->nullable()->unique();

            $table->enum('status', PaymentStatus::values())->default(PaymentStatus::Received->value);

            $table->foreignId('branch_id')->nullable()->constrained('branches')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('teller_id')->nullable()->constrained('users')
                ->nullOnDelete()->cascadeOnUpdate();

            $table->timestamp('received_at');
            $table->timestamp('confirmed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')
                ->nullOnDelete()->cascadeOnUpdate();

            // The entry this payment produced, for tracing money to ledger.
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete()->cascadeOnUpdate();

            $table->timestamps();

            $table->index('loan_id');
            $table->index('status');
            $table->index('branch_id');
            $table->index('received_at');
        });

        /*
         * One row per installment a payment touched, in
         * Penalty → Interest → Principal order (§7). This is the audit trail
         * that explains how a single payment was spread across a schedule.
         */
        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('loan_schedule_id')->constrained('loan_schedules')
                ->restrictOnDelete()->cascadeOnUpdate();

            $table->decimal('penalty_allocated', 18, 2)->default(0);
            $table->decimal('interest_allocated', 18, 2)->default(0);
            $table->decimal('principal_allocated', 18, 2)->default(0);

            $table->timestamp('created_at')->useCurrent();

            $table->index('payment_id');
            $table->index('loan_schedule_id');
        });

        /*
         * §7: money that cannot be matched to a loan still lands in the
         * ledger the moment it arrives (Dr Cash / Cr Suspense). This row is
         * the work queue for resolving it.
         */
        Schema::create('suspense_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->string('reason', 255);
            $table->decimal('amount', 18, 2);
            $table->enum('status', SuspenseStatus::values())->default(SuspenseStatus::Unallocated->value);
            $table->foreignId('resolved_by')->nullable()->constrained('users')
                ->nullOnDelete()->cascadeOnUpdate();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('payment_id');
        });

        /*
         * §7: teller cash-in-hand and bank-confirmed cash are two different
         * trust states. A cash payment stays `pending_verification` until a
         * deposit is reconciled against it.
         */
        Schema::create('cash_deposits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('teller_id')->constrained('users')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('branch_id')->constrained('branches')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->decimal('amount', 18, 2);
            $table->foreignId('bank_account_id')->constrained('bank_accounts')
                ->restrictOnDelete()->cascadeOnUpdate();

            // Deposit slip, on the same private disk as KYC documents (§1).
            $table->string('deposit_slip_path', 255)->nullable();

            $table->enum('status', CashDepositStatus::values())->default(CashDepositStatus::Pending->value);
            $table->json('matched_payment_ids')->nullable();

            $table->foreignId('reconciled_by')->nullable()->constrained('users')
                ->nullOnDelete()->cascadeOnUpdate();
            $table->timestamp('reconciled_at')->nullable();

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete()->cascadeOnUpdate();

            $table->timestamps();

            $table->index('branch_id');
            $table->index('status');
        });

        /*
         * §7's scheduled `penalty:apply` job. One row per run, recording what
         * it touched — which is what makes an unexplained jump in penalty
         * income traceable to a specific execution.
         */
        Schema::create('penalty_runs', function (Blueprint $table): void {
            $table->id();
            $table->date('run_date');
            $table->unsignedInteger('loans_processed')->default(0);
            $table->unsignedInteger('installments_penalised')->default(0);
            $table->decimal('total_penalty_applied', 18, 2)->default(0);
            $table->enum('triggered_by', TriggeredBy::values())->default(TriggeredBy::Cron->value);
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')
                ->nullOnDelete()->cascadeOnUpdate();
            $table->timestamp('created_at')->useCurrent();

            $table->index('run_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penalty_runs');
        Schema::dropIfExists('cash_deposits');
        Schema::dropIfExists('suspense_items');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};

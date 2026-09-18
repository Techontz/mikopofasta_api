<?php

declare(strict_types=1);

use App\Domain\Reversals\Enums\ReversalType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widens `reversal_requests` from "reverse a journal entry" to "reverse a
 * transaction".
 *
 * The original table could only name a journal entry, which made it a purely
 * accounting instrument: approving one posted a mirrored entry and left the
 * loan alone. A payment reversal that leaves the borrower's schedule marked
 * paid is worse than no reversal at all — the books say the money went back
 * and the loan says it was received.
 *
 * So a request now names WHAT is being reversed, and the approval undoes the
 * business fact as well as the posting:
 *
 *   payment       — a receipt: un-allocate it, restore the schedule, drop the
 *                   advance movements, re-derive the loan's status
 *   disbursement  — a payout: the loan goes back to awaiting_disbursement
 *   penalty       — accrued `penalty_due` on one installment. It has no
 *                   journal entry, because §5 recognises penalty income on
 *                   COLLECTION, not on accrual — hence journal_entry_id
 *                   becoming nullable below.
 *   ledger        — the original behaviour, kept for entries no workflow owns
 *                   (expenses, transfers, payroll).
 *
 * Existing rows are all `ledger` by definition, which is the column default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reversal_requests', function (Blueprint $table): void {
            $table->enum('reversal_type', ReversalType::values())
                ->default(ReversalType::Ledger->value)
                ->after('id');

            /*
             * The subject. Exactly one is set, decided by `reversal_type` —
             * enforced in RequestReversalAction rather than by a CHECK
             * constraint, because MySQL 5.7 (still the deployment target for
             * one branch DB) parses CHECK and silently ignores it, which is
             * the worst of both worlds.
             */
            $table->foreignId('payment_id')->nullable()->after('journal_entry_id')
                ->constrained('payments')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('disbursement_batch_id')->nullable()->after('payment_id')
                ->constrained('disbursement_batches')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('loan_schedule_id')->nullable()->after('disbursement_batch_id')
                ->constrained('loan_schedules')->restrictOnDelete()->cascadeOnUpdate();

            /*
             * Denormalised so the queue can show and filter by loan without
             * four different joins depending on the type.
             */
            $table->foreignId('loan_id')->nullable()->after('loan_schedule_id')
                ->constrained('loans')->restrictOnDelete()->cascadeOnUpdate();

            /*
             * What the request proposes to reverse, snapshotted when it is
             * raised. An approver must see the figure they saw when they
             * decided, not one recomputed after the fact — and for a penalty
             * waiver it is the only record of what was written off, since
             * clearing `penalty_due` destroys the evidence.
             */
            $table->decimal('amount', 18, 2)->nullable()->after('reason');

            $table->index(['reversal_type', 'status']);
            $table->index('loan_id');
        });

        // Penalty reversals have no entry to name.
        DB::statement('ALTER TABLE reversal_requests MODIFY journal_entry_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        Schema::table('reversal_requests', function (Blueprint $table): void {
            $table->dropForeign(['payment_id']);
            $table->dropForeign(['disbursement_batch_id']);
            $table->dropForeign(['loan_schedule_id']);
            $table->dropForeign(['loan_id']);
            $table->dropIndex(['reversal_type', 'status']);
            $table->dropIndex(['loan_id']);
            $table->dropColumn([
                'reversal_type', 'payment_id', 'disbursement_batch_id',
                'loan_schedule_id', 'loan_id', 'amount',
            ]);
        });

        DB::statement('ALTER TABLE reversal_requests MODIFY journal_entry_id BIGINT UNSIGNED NOT NULL');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advance payments — money a borrower pays before it is due.
 *
 * Step 2 of the confirmed allocation order (Penalty → Advance → Principal →
 * Interest), and the "customers may pay before due date" requirement.
 *
 * ## Why a ledger of movements rather than one balance column
 *
 * A single `loans.advance_balance` would answer "how much is held" and nothing
 * else. It could not say when the credit arrived, which payment brought it, or
 * which installment consumed it — and the requirement is explicit that advances
 * "maintain full audit trail".
 *
 * So every movement is a row, and the balance is their sum. That makes the
 * balance derivable and therefore checkable: a corrupted balance column can
 * disagree with reality, a sum cannot.
 *
 * ## Sign convention
 *
 * `amount` is signed. Positive is a credit — the borrower paid ahead. Negative
 * is a consumption — an installment spent some of it. The balance is the sum,
 * and it can never legitimately go below zero because the allocator only ever
 * spends what is there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_advances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete()->cascadeOnUpdate();

            /*
             * The payment that created this credit. Null on a consumption,
             * which is caused by an allocation rather than by money arriving.
             */
            $table->foreignId('payment_id')->nullable()
                ->constrained('payments')->nullOnDelete()->cascadeOnUpdate();

            /** Positive credits the borrower, negative consumes the credit. */
            $table->decimal('amount', 18, 2);

            /*
             * The running balance after this movement.
             *
             * Denormalised on purpose. The balance is still DERIVABLE from the
             * sum of `amount`, so this column is a witness rather than the
             * truth — and a statement that shows the balance after every
             * movement is what makes an advance ledger readable at all.
             */
            $table->decimal('balance_after', 18, 2);

            $table->enum('kind', ['credit', 'consumption', 'refund'])->default('credit');
            $table->string('narrative', 255)->nullable();

            /*
             * The entry that recognised this movement, where one exists.
             *
             * A credit posts (the money genuinely arrived and must reach the
             * books). A consumption does NOT: the cash was already recognised
             * when it was received, and posting again would recognise the same
             * shilling twice.
             */
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The two questions asked of this table: this loan's statement, and
            // its current balance.
            $table->index(['loan_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_advances');
    }
};

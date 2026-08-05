<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two ends of a bad debt — §5's Write-Off and Recovered Loans accounts.
 *
 * Both statuses and both account codes (4200, 4300) already existed; what did
 * not exist was anything that could move a loan into them or post the entries
 * §5 defines. The Recovery report consequently listed loan STATES rather than
 * ledger balances — a report about money that never consulted the ledger.
 *
 * Deliberately two tables and not one. A write-off is a decision the business
 * makes about a loan; a recovery is money arriving afterwards. One loan may be
 * written off once and recovered many times, in instalments, by a recovery
 * officer chasing it for a year — a single table would have to leave half its
 * columns null for every row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('write_offs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();

            /*
             * Split by component, because what is forgiven differs from what
             * was owed. §5's posting credits Loan Receivable with the
             * principal only — interest and penalty that were never collected
             * were never recognised as income under the collection basis this
             * system uses, so writing them off would reverse income that does
             * not exist. They are recorded here for the arrears report and for
             * the recovery officer, not for the ledger.
             */
            $table->decimal('principal_written_off', 18, 2);
            $table->decimal('interest_forgone', 18, 2)->default(0);
            $table->decimal('penalty_forgone', 18, 2)->default(0);

            $table->string('reason', 500);

            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();

            $table->timestamps();

            // A loan is written off once. A second write-off would double the
            // expense and halve the receivable twice.
            $table->unique('loan_id');
        });

        Schema::create('recoveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $table->foreignId('write_off_id')->constrained('write_offs')->restrictOnDelete();

            $table->decimal('amount', 18, 2);

            /*
             * The payment that carried the money in, where one exists. Null for
             * a recovery banked directly — a settlement negotiated by a
             * recovery officer does not always arrive through the payment
             * rails, and forcing a synthetic payment row would put money in the
             * repayment reports that never repaid a schedule.
             */
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();

            $table->string('narrative', 500)->nullable();

            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();

            $table->timestamps();

            $table->index(['loan_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recoveries');
        Schema::dropIfExists('write_offs');
    }
};

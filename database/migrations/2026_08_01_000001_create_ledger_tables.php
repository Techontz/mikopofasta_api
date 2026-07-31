<?php

declare(strict_types=1);

use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Enums\ReversalStatus;
use App\Enums\ActiveStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backend spec §2.2 (`bank_accounts`) and §2.7 (the ledger core).
     *
     * The defining property of this migration is what is ABSENT. Neither
     * `journal_entries` nor `journal_entry_lines` has a `deleted_at`, and
     * neither has `updated_at`: §2 states plainly that ledger-adjacent tables
     * have "no `deleted_at` column at all — deletion is architecturally
     * impossible; the only way to undo money movement is a reversal entry".
     * The models enforce the same at the application layer (§8).
     */
    public function up(): void
    {
        /*
         * §2.2. Deferred in Phase 3 because the frontend has no bank-account
         * CRUD screen (readiness report gap 3); created now because
         * `cash_deposits` references it and every bank account owns an 8xxx
         * chart account. Seeded, with no CRUD endpoints — same treatment as
         * groups in Phase 4.
         */
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('bank_name', 100);
            $table->string('account_number', 50)->unique();
            $table->string('account_name', 150);

            // Set immediately after the chart account is created; the FK is
            // added at the end of this migration once that table exists.
            $table->unsignedBigInteger('chart_account_id')->nullable();

            $table->enum('status', ActiveStatus::values())->default(ActiveStatus::Active->value);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('chart_of_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->enum('type', AccountType::values());

            $table->foreignId('parent_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete()->cascadeOnUpdate();

            // True for the fixed §5 accounts; false for dynamic ones (one per
            // bank account, one teller-cash account per branch, one per
            // expense category).
            $table->boolean('is_system')->default(false);

            $table->foreignId('branch_id')->nullable()
                ->constrained('branches')->restrictOnDelete()->cascadeOnUpdate();

            $table->enum('status', ActiveStatus::values())->default(ActiveStatus::Active->value);
            $table->timestamps();
            $table->softDeletes();

            $table->index('type');
            $table->index('branch_id');
            $table->index('is_system');
        });

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->foreign('chart_account_id')->references('id')->on('chart_of_accounts')
                ->restrictOnDelete()->cascadeOnUpdate();
        });

        /*
         * Immutable. No deleted_at, no updated_at — an entry is written once
         * and never touched again (§2.7, §8).
         */
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('entry_number', 30)->unique();
            $table->date('entry_date');
            $table->string('description', 255);

            $table->enum('source_type', JournalSourceType::values());
            $table->unsignedBigInteger('source_id')->nullable();

            $table->boolean('is_reversal')->default(false);
            $table->foreignId('reversed_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete()->cascadeOnUpdate();

            $table->foreignId('created_by')->constrained('users')
                ->restrictOnDelete()->cascadeOnUpdate();

            $table->timestamp('posted_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['source_type', 'source_id']);
            $table->index('entry_date');
            $table->index('is_reversal');

            // A given entry can be reversed at most once; the partial-unique
            // behaviour comes free because the column is NULL for non-reversals.
            $table->unique('reversed_entry_id');
        });

        Schema::create('journal_entry_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('account_id')->constrained('chart_of_accounts')
                ->restrictOnDelete()->cascadeOnUpdate();

            $table->decimal('debit_amount', 18, 2)->default(0);
            $table->decimal('credit_amount', 18, 2)->default(0);

            /*
             * The four analysis dimensions. §2.7: Customer, Loan, Staff and
             * Branch "ledgers" are NOT separate tables — they are these lines
             * filtered by the matching id. One physical source of truth.
             */
            $table->foreignId('branch_id')->nullable()->constrained('branches')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('customer_id')->nullable()->constrained('customers')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('loan_id')->nullable()->constrained('loans')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->unsignedBigInteger('staff_profile_id')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('journal_entry_id');
            $table->index('account_id');
            $table->index('branch_id');
            $table->index('customer_id');
            $table->index('loan_id');
            $table->index('staff_profile_id');
        });

        /*
         * A materialized cache, never an independent source of truth (§2.7).
         * Rebuilt from journal_entry_lines by LedgerService on every post, and
         * fully recomputable at any time.
         */
        Schema::create('account_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('chart_of_accounts')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('branch_id')->nullable()->constrained('branches')
                ->cascadeOnDelete()->cascadeOnUpdate();

            $table->decimal('debit_total', 18, 2)->default(0);
            $table->decimal('credit_total', 18, 2)->default(0);

            // Signed by the account's normal side.
            $table->decimal('balance', 18, 2)->default(0);

            $table->timestamp('last_updated_at');
            $table->timestamps();

            $table->unique(['account_id', 'branch_id']);
        });

        /*
         * §14: requesting a reversal and approving one are different
         * permissions held by different roles. This table records both sides.
         */
        Schema::create('reversal_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('requested_by')->constrained('users')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->string('reason', 255);
            $table->foreignId('approved_by')->nullable()->constrained('users')
                ->nullOnDelete()->cascadeOnUpdate();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();

            // The entry created when the reversal is approved.
            $table->foreignId('reversal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete()->cascadeOnUpdate();

            $table->enum('status', ReversalStatus::values())->default(ReversalStatus::Pending->value);
            $table->timestamps();

            $table->index('journal_entry_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reversal_requests');
        Schema::dropIfExists('account_balances');
        Schema::dropIfExists('journal_entry_lines');
        Schema::dropIfExists('journal_entries');

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->dropForeign(['chart_account_id']);
        });

        Schema::dropIfExists('chart_of_accounts');
        Schema::dropIfExists('bank_accounts');
    }
};

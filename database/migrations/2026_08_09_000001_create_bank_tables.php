<?php

declare(strict_types=1);

use App\Domain\Treasury\Enums\BankTransactionStatus;
use App\Domain\Treasury\Enums\BankTransactionType;
use App\Domain\Treasury\Enums\BankTransferKind;
use App\Domain\Treasury\Enums\BankTransferStatus;
use App\Domain\Treasury\Enums\Currency;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Bank module — sidebar → Bank. See docs/modules/bank.md.
 *
 * `bank_accounts` already existed, seeded and read but never written: the
 * original migration says so outright, "the frontend has no bank-account CRUD
 * screen (readiness report gap 3), so this is seeded and read, never managed
 * through an endpoint". That gap is closed — the rebuilt frontend has Register
 * Account — and the table grows the four fields that screen collects and the
 * table lacks.
 *
 * Two new tables for the movements the module's own screens show. Neither
 * duplicates the ledger: both carry a `journal_entry_id`, and the money itself
 * lives in `journal_entry_lines` like everything else. What they add is the
 * request-and-approval life the ledger has no concept of — a journal entry is
 * a fact, and these are proposals until someone agrees to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table): void {
            /*
             * The branch that holds the account. Nullable because head office
             * accounts predate branches in this data and because the legacy
             * Register Account screen was captured showing only a name — see
             * the branch column on the rebuilt form, which is required there.
             */
            $table->foreignId('branch_id')->nullable()->after('account_name')
                ->constrained('branches')->restrictOnDelete();

            $table->enum('currency', Currency::values())
                ->default(Currency::Tzs->value)
                ->after('branch_id');

            /*
             * What the account held when it was registered.
             *
             * Not a balance: the balance is the 8xxx chart account's, derived
             * from journal lines like every other balance in this system. This
             * is the figure the opening entry posts, kept so the Account
             * Balance screen can show what the account started with beside what
             * it holds now.
             */
            $table->decimal('opening_balance', 18, 2)->default(0)->after('currency');

            $table->string('description', 500)->nullable()->after('opening_balance');

            $table->foreignId('created_by')->nullable()->after('description')
                ->constrained('users')->nullOnDelete();

            $table->index(['status', 'branch_id']);
        });

        /*
         * Bank Transaction and Approved Transaction — one table, two screens,
         * for the same reason the expense queues share one: an approved
         * transaction is not a different record from a pending one, it is the
         * same record later.
         */
        Schema::create('bank_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 20)->unique();

            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();

            $table->enum('type', BankTransactionType::values());
            $table->decimal('amount', 18, 2);

            // The branch the movement is attributed to, so bank activity shows
            // in branch reporting rather than only in the consolidated total.
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->enum('status', BankTransactionStatus::values())
                ->default(BankTransactionStatus::Pending->value);

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('note', 500)->nullable();

            // Null while pending, and forever if rejected. Money moves on
            // approval, which is the rule every queue in this system follows.
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->date('transacted_on');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'transacted_on']);
            $table->index(['bank_account_id', 'status']);
        });

        /*
         * The two Transfer Balance screens. One table, because both move money
         * between two accounts and differ only in which accounts they offer and
         * what the transfer is called on the menu.
         */
        Schema::create('bank_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();

            $table->enum('kind', BankTransferKind::values());

            $table->foreignId('from_account_id')->constrained('bank_accounts')->restrictOnDelete();

            /*
             * Where it went. Exactly one of these is set.
             *
             * Branch transfers name a branch — the money lands in that branch's
             * teller cash. Salary-advance and disbursement transfers name
             * another bank account. Modelling both as "destination" columns
             * rather than one polymorphic pair keeps the foreign keys real.
             */
            $table->foreignId('to_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('to_branch_id')->nullable()->constrained('branches')->restrictOnDelete();

            $table->decimal('amount', 18, 2);

            /*
             * The bank's own charge for making the transfer. Posted as fee
             * expenditure in the same entry, not netted off the amount — the
             * destination receives the full amount and the charge is a separate
             * cost, which is what makes it visible as one.
             */
            $table->decimal('charge_fee', 18, 2)->default(0);

            $table->string('reason', 120);
            $table->string('description', 500)->nullable();

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->enum('status', BankTransferStatus::values())
                ->default(BankTransferStatus::Pending->value);

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->date('transferred_on');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['kind', 'status', 'transferred_on']);
        });

        /*
         * Register Bank Expenses — Bank → Register Bank Expenses.
         *
         * An expense paid from a bank account rather than out of the branch
         * till. This is one nullable column on the existing expense request
         * rather than a second expense system: the record is the same shape,
         * the approval is the same approval, and only the credit side of the
         * posting differs. Two tables would have meant two Expense Tagging
         * Reports and two chances for them to disagree.
         */
        Schema::table('expense_requests', function (Blueprint $table): void {
            $table->foreignId('bank_account_id')->nullable()->after('branch_id')
                ->constrained('bank_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expense_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bank_account_id');
        });

        Schema::dropIfExists('bank_transfers');
        Schema::dropIfExists('bank_transactions');

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->dropIndex(['status', 'branch_id']);
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['currency', 'opening_balance', 'description']);
        });
    }
};

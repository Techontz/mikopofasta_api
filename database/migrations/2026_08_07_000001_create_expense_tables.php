<?php

declare(strict_types=1);

use App\Domain\Expenses\Enums\ExpenseRequestStatus;
use App\Domain\Expenses\Enums\ExpenseScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expenses — the named things the company spends against, and the requests
 * filed under them. See docs/modules/expenses.md.
 *
 * Two tables for what the legacy system draws as six screens, because the six
 * are two records seen from different angles: three branch screens and three
 * headquarters ones, each set being a register of names plus a request queue
 * plus that queue filtered to approved.
 *
 * The accounting shape is fixed by the business documentation rather than
 * chosen here. ACCOUNT OVERVIEW §G ("Expense Accounts (Dynamic)") says the
 * administrator creates the categories — Umeme, Rent, Usafiri — and that each
 * category is its own ledger: "Kila category: = Ledger yake". So a category is
 * not a label on a shared expense account; it owns one, and `chart_account_id`
 * is that ownership. It is what makes the Branch Expense Report's "expense
 * categories (rent, fuel, etc.)" breakdown a grouped ledger query rather than
 * a string match on a description field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->id();

            // Stored as entered. The legacy register holds "umeme", "MAJI" and
            // "SODA" side by side, and normalising the case would rewrite the
            // company's own vocabulary for it.
            $table->string('name', 120);

            $table->enum('scope', ExpenseScope::values());

            /*
             * The 6xxx expense account this category owns, created with the
             * category and never shared. Not nullable: a category without a
             * ledger cannot be spent against, so there is no valid moment for
             * this to be empty.
             */
            $table->foreignId('chart_account_id')->constrained('chart_of_accounts')->restrictOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Soft-deleted: a request already filed keeps naming its category,
            // and last year's Branch P&L must still be able to resolve it.
            $table->softDeletes();

            /*
             * One live name per register — head office and a branch may both
             * keep a "Stationery", and they are different budget lines, but
             * neither may keep two.
             *
             * The third column is a sentinel, not `deleted_at` itself, and the
             * distinction is the whole point. MySQL treats NULLs in a unique
             * index as distinct from one another, so indexing `deleted_at`
             * directly would constrain nothing at all among live rows — every
             * one of them holds NULL there, and MySQL would consider them all
             * different. Collapsing live rows onto a shared literal makes them
             * collide as intended, while a deleted row carries its own deletion
             * timestamp and so frees the name for reuse.
             *
             * The column's collation is utf8mb4_unicode_ci, which is
             * case-insensitive — so "Rent" and "rent" collide here exactly as
             * they do in CreateExpenseCategoryAction's own check, and the index
             * and the application agree on what a duplicate is.
             *
             * A string rather than a timestamp so the live sentinel is a word
             * and not a date that never happened. Nothing reads this column;
             * it exists only to be indexed.
             */
            $table->string('deleted_marker', 30)->virtualAs("COALESCE(CAST(deleted_at AS CHAR), 'live')");
            $table->unique(['name', 'scope', 'deleted_marker']);
            $table->index(['scope', 'deleted_at']);
        });

        Schema::create('expense_requests', function (Blueprint $table): void {
            $table->id();

            // EXP-0000001, generated the same way as JE- and LN- numbers.
            $table->string('reference', 20)->unique();

            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();

            /*
             * Copied from the category rather than joined for. A request is
             * filed against one register and stays in it even if the category
             * is later reclassified, and every one of the four list screens
             * filters on this column first — denormalising it is what keeps
             * those screens a single indexed read.
             */
            $table->enum('scope', ExpenseScope::values());

            /*
             * The branch that bears the cost, and never null.
             *
             * A branch request names one (defaulting to the requester's own);
             * a headquarters request is booked to the head-office branch, which
             * is a branch like any other here — it holds a teller cash account
             * and appears in branch reporting. Leaving this nullable would put
             * head-office spending in the NULL-branch bucket of
             * `account_balances`, where no branch-scoped report can see it, and
             * would give every reader a null to defend against that a committed
             * row never holds.
             */
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();

            $table->decimal('amount', 18, 2);
            $table->string('description', 255);

            // The approver's note, not the requester's. The legacy screen shows
            // it in its own column beside the description and leaves it blank
            // until someone decides.
            $table->string('comment', 300)->nullable();

            $table->enum('status', ExpenseRequestStatus::values())
                ->default(ExpenseRequestStatus::Pending->value);

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            /*
             * Null while pending, and null forever if rejected. Money leaves on
             * approval, not on request — the same rule branch-to-branch float
             * follows, and the reason a queue of requests never moves the trial
             * balance.
             */
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            // The date the cost was incurred, which is not always the date the
            // row was created — a receipt gets filed after the fact.
            $table->date('requested_on');

            $table->timestamps();
            $table->softDeletes();

            // What the four list screens sort and filter by.
            $table->index(['scope', 'status', 'requested_on']);
            $table->index(['branch_id', 'status']);
            $table->index('expense_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_requests');
        Schema::dropIfExists('expense_categories');
    }
};

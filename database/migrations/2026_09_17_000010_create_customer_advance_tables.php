<?php

declare(strict_types=1);

use App\Domain\CustomerAdvances\Enums\CustomerAdvanceStatus;
use App\Domain\Ledger\Enums\SystemAccountCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salary Advance (Customer) — the register and its collections.
 *
 * ## Why a new pair of tables rather than a loan product
 *
 * The chart already carries a `SALARY_ADVANCE` loan product, and an advance
 * could have been forced through the loan pipeline. It was not, because that
 * pipeline is built on things an advance does not have: an amortised schedule,
 * penalties, an e-mandate, an approval route through zone and credit review. A
 * loan with all of those switched off is a worse record than a table that says
 * what an advance is.
 *
 * What an advance *is*: a principal, a one-off interest figure, a flat charge
 * fee, and a number of periods to clear it in — the same four the staff advance
 * carries, priced from the same `salary_advance_categories` bands. One band
 * ladder serves both sides, because the legacy menu has exactly one Salary
 * Advance Category screen and pricing the same product two ways from two tables
 * is how the two drift apart.
 *
 * ## What the money does — the client's rule, in their words
 *
 * > "wakati wa maombi itatoka kwenye Principal Operation na wakati wa malipo
 * > sehemu ya mtaji itarudi Principal Operation na faida itaingia Income
 * > Operation"
 *
 * and, when asked whether Salary Advance holds a balance of its own:
 *
 * > "Salary Advance = dashboard summary + transaction history, NOT a separate
 * > cash account."
 *
 * So: issuing pays out of the operational money (a company bank account or the
 * branch till) and books the debt to 1250; a collection clears 1250 with the
 * capital portion and credits 2000/2100 with the profit. No shilling is ever
 * parked under "Salary Advance" — `funding_account_id` records which real
 * account it left, which is what makes that claim checkable.
 *
 * ## Why the repaid figure is stored in three columns
 *
 * `principal_repaid`, `interest_repaid` and `fee_repaid` are what the ledger
 * was credited with, cumulatively. Keeping only a single `amount_repaid` and
 * re-deriving the split per payment lets rounding drift: three payments of a
 * third each would each round their own principal share, and 1250 would finish
 * a cent or two away from zero with nothing to say which payment did it. The
 * split is therefore computed from the cumulative total and stored, so the
 * receivable closes exactly.
 *
 * @see docs/modules/salary-advance-customer.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_advances', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();

            $table->foreignId('customer_id')->constrained('customers')
                ->restrictOnDelete()->cascadeOnUpdate();

            /*
             * Denormalised from the customer at request, not read through it.
             * A customer transferred to another branch next year must not move
             * last year's advance — and every branch figure on the dashboard is
             * this column.
             */
            $table->foreignId('branch_id')->nullable()->constrained('branches')
                ->nullOnDelete()->cascadeOnUpdate();

            $table->foreignId('salary_advance_category_id')->nullable()
                ->constrained('salary_advance_categories')->nullOnDelete()->cascadeOnUpdate();

            // The terms, snapshotted at request — re-pricing a band must never
            // rewrite an advance already agreed with a customer.
            $table->decimal('amount', 18, 2);
            $table->decimal('interest_amount', 18, 2)->default(0);
            $table->decimal('charge_fee', 18, 2)->default(0);
            $table->unsignedSmallInteger('recovery_periods')->default(1);

            // What has been collected, and what each shilling of it was.
            $table->decimal('amount_repaid', 18, 2)->default(0);
            $table->decimal('principal_repaid', 18, 2)->default(0);
            $table->decimal('interest_repaid', 18, 2)->default(0);
            $table->decimal('fee_repaid', 18, 2)->default(0);

            $table->enum('status', CustomerAdvanceStatus::values())
                ->default(CustomerAdvanceStatus::Requested->value);

            $table->timestamp('requested_at');
            $table->foreignId('requested_by')->nullable()->constrained('users')
                ->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('approved_by')->nullable()->constrained('users')
                ->nullOnDelete()->cascadeOnUpdate();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('disbursed_by')->nullable()->constrained('users')
                ->nullOnDelete()->cascadeOnUpdate();
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->date('due_date')->nullable();
            $table->string('rejection_reason', 255)->nullable();

            /*
             * The real account the money left. The client's rule is that no
             * balance sits under Salary Advance; this column is the evidence,
             * because it names a bank account or a branch till every time.
             */
            $table->foreignId('funding_account_id')->nullable()->constrained('chart_of_accounts')
                ->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')
                ->nullOnDelete()->cascadeOnUpdate();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'branch_id']);
            $table->index('customer_id');
            // The dashboard's month figure is "issued this month, by branch".
            $table->index(['disbursed_at', 'branch_id']);
        });

        Schema::create('customer_advance_payments', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();

            $table->foreignId('customer_advance_id')->constrained('customer_advances')
                ->cascadeOnDelete()->cascadeOnUpdate();

            // Copied from the advance so the month-by-branch summary is one
            // grouped read rather than a join on every dashboard load.
            $table->foreignId('branch_id')->nullable()->constrained('branches')
                ->nullOnDelete()->cascadeOnUpdate();

            $table->decimal('amount', 18, 2);

            // The three credits this payment posted, kept so the register and
            // the ledger can be reconciled row by row rather than in total.
            $table->decimal('principal_portion', 18, 2)->default(0);
            $table->decimal('interest_portion', 18, 2)->default(0);
            $table->decimal('fee_portion', 18, 2)->default(0);

            $table->string('channel', 30);
            $table->string('note', 255)->nullable();

            /*
             * The business date of the collection, which is what the monthly
             * summary and every report group by. Deliberately not `created_at`:
             * a payment entered the morning after is September's money when the
             * teller says it is, and `created_at` cannot be told that.
             */
            $table->timestamp('paid_at');

            $table->foreignId('received_by')->nullable()->constrained('users')
                ->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')
                ->nullOnDelete()->cascadeOnUpdate();

            $table->timestamps();

            $table->index(['paid_at', 'branch_id']);
            $table->index('customer_advance_id');
        });

        $this->createReceivableAccount();
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_advance_payments');
        Schema::dropIfExists('customer_advances');

        /*
         * The account is left in place. Dropping it would fail against any
         * journal line that referenced it, and an account with history is not
         * something a rollback should be able to delete.
         */
    }

    /**
     * 1250 Salary Advance Receivable, minted here as well as in the seeder.
     *
     * ChartOfAccountSeeder creates every system account, but a production
     * database is migrated and not re-seeded — and the first advance disbursed
     * would fail in AccountResolver with "System account [1250] is missing".
     * Idempotent, so the seeder running afterwards changes nothing.
     */
    private function createReceivableAccount(): void
    {
        $code = SystemAccountCode::CustomerAdvanceReceivable;

        $exists = DB::table('chart_of_accounts')->where('code', $code->value)->exists();

        if ($exists) {
            return;
        }

        DB::table('chart_of_accounts')->insert([
            'code' => $code->value,
            'name' => $code->accountName(),
            'type' => $code->type()->value,
            'is_system' => true,
            'branch_id' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};

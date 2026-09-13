<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns `bank_accounts` into what it actually is: the company's money
 * accounts, of any kind.
 *
 * ## What was wrong
 *
 * The table modelled one kind of account — a bank one — owned by a branch:
 *
 *   bank_name   free text, unrelated to the `banks` master data beside it
 *   branch_id   a company bank account does not belong to a branch
 *   (no way to record a mobile money wallet at all)
 *   (no way to say whether an account receives money, sends it, or both)
 *
 * A company's financial channel is not a branch's property. Branch stays
 * everywhere it is genuinely organizational — this removes it from ONE place,
 * where it was wrong.
 *
 * ## What it does not touch
 *
 * `chart_account_id` is untouched, and so is every journal entry and balance.
 * Each money account already points at its own asset row in the chart of
 * accounts, which is what keeps "where the money is" separate from "what the
 * money represents", and what keeps the ledger the single source of truth for
 * balances. That separation was already right; this only widens what can be
 * registered as a channel.
 *
 * ## Existing data
 *
 * Both existing rows are banks, both already have `branch_id` NULL, and both
 * keep their account number and their chart account. They land on
 * `account_type = bank` and `usage = both`, which is exactly how they behave
 * today — no account stops working and no selector loses an option.
 *
 * `bank_name` is KEPT rather than replaced. It is backfilled into `bank_id`
 * where the text matches a bank in the master data, but a row whose text
 * matches nothing keeps its name and reads correctly; dropping the column
 * would lose that account's identity to make the schema tidier.
 *
 * ## Rollback
 *
 * Safe. `branch_id` returns nullable, which is what it was and what every row
 * held. Registered MNO accounts cannot be expressed in the old shape, so
 * `down()` refuses rather than silently mangling them into bank rows — see the
 * guard there.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The index goes before the column it names. Dropping `branch_id`
         * first would leave `(status, branch_id)` half-defined, and MySQL's
         * behaviour there is a footnote rather than a guarantee.
         */
        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->dropIndex('bank_accounts_status_branch_id_index');
        });

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('bank_accounts', function (Blueprint $table): void {
            /* Which kind of channel this is. Cash is deliberately absent: it
               is a payment METHOD, not a registered account, and inventing a
               "Cash Bank" row to hold it would be exactly the fake record the
               brief rules out. Cash posts to its existing chart account. */
            $table->enum('account_type', ['bank', 'mno'])
                ->default('bank')
                ->after('id');

            /* Which direction(s) this account may be selected for. `both` is
               the default because that is how the two existing accounts are
               used, and because splitting one physical account into a
               "collection" and a "disbursement" copy is the modelling error
               this column exists to prevent. Direction belongs to the
               transaction; the account is the account. */
            $table->enum('usage', ['collection', 'disbursement', 'both'])
                ->default('both')
                ->after('account_type');

            /* The provider, from master data rather than free text. Both are
               nullable because exactly one applies to any given row, and
               `restrictOnDelete` because a provider some account references
               must be deactivated, not deleted out from under it. */
            $table->foreignId('bank_id')->nullable()->after('bank_name')
                ->constrained('banks')->restrictOnDelete()->cascadeOnUpdate();

            $table->foreignId('mobile_money_provider_id')->nullable()->after('bank_id')
                ->constrained('mobile_money_providers')->restrictOnDelete()->cascadeOnUpdate();

        });

        /*
         * Backfill `bank_id` where the stored text names a bank we know.
         *
         * Best-effort by design. A row whose bank_name matches nothing keeps
         * working on its text; it is not deleted, defaulted to some other
         * bank, or blocked.
         */
        foreach (DB::table('banks')->whereNull('deleted_at')->get(['id', 'name', 'code']) as $bank) {
            DB::table('bank_accounts')
                ->whereNull('bank_id')
                ->where(function ($q) use ($bank): void {
                    $q->where('bank_name', $bank->name)->orWhere('bank_name', $bank->code);
                })
                ->update(['bank_id' => $bank->id]);
        }

        /*
         * Uniqueness moves from the raw number alone to the physical account:
         * kind, provider, and the normalised number.
         *
         * ONE GENERATED COLUMN, NOT A COMPOSITE OF FOUR — and the difference
         * is not stylistic. A composite over
         * `(account_type, bank_id, mobile_money_provider_id, account_number_key)`
         * enforces NOTHING for a mobile money wallet, because `bank_id` is
         * NULL on those rows and MySQL treats NULLs in a unique index as
         * distinct from each other. That version was written, applied, and
         * then accepted the same M-Pesa wallet twice — once as "0754 123 456"
         * and once as "0754-123-456". The test that caught it is in
         * CompanyPaymentChannelTest.
         *
         * Collapsing the scope into one NOT NULL string removes the NULL
         * entirely: `COALESCE` picks whichever provider applies, and 0 stands
         * for neither.
         *
         * VIRTUAL, not STORED, and that is forced rather than chosen. A STORED
         * generated column may not depend on a column carrying a foreign key
         * with ON UPDATE CASCADE — which `bank_id` and
         * `mobile_money_provider_id` both do, because renumbering a provider
         * should follow through to the accounts that reference it. MySQL
         * rejects the combination outright with errno 1215. A VIRTUAL column
         * carries no such restriction, and MySQL 8 indexes one perfectly well:
         * the unique constraint below is enforced on the index, which is all
         * this column exists for.
         */
        /*
         * BOTH keys are derived by the database, and that is the whole point.
         *
         * The first version made `account_number_key` an ordinary column and
         * backfilled it here. Every existing row was correct and every FUTURE
         * insert was wrong: ChartOfAccountSeeder — and any action, import or
         * fixture — inserts a bank account without mentioning it, so the key
         * came out empty, `physical_account_key` collapsed to the constant
         * 'bank:0:', and the second such insert violated the unique index.
         * That took 680 tests down. A constraint whose input the caller has to
         * remember to populate is not a constraint; it is a trap.
         *
         * Deriving it removes the class of bug outright. No insert path can
         * get it wrong because no insert path supplies it.
         */
        DB::statement(
            'ALTER TABLE bank_accounts ADD COLUMN account_number_key VARCHAR(50) '
            ."GENERATED ALWAYS AS (UPPER(REGEXP_REPLACE(account_number, '[^A-Za-z0-9]', ''))) VIRTUAL NOT NULL",
        );

        DB::statement(
            'ALTER TABLE bank_accounts ADD COLUMN physical_account_key VARCHAR(120) '
            .'GENERATED ALWAYS AS (CONCAT('
            ."account_type, ':', COALESCE(bank_id, mobile_money_provider_id, 0), ':', account_number_key"
            .')) VIRTUAL NOT NULL',
        );

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->unique('physical_account_key', 'bank_accounts_physical_account_unique');
        });

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->dropUnique('bank_accounts_account_number_unique');
            $table->index(['status', 'account_type', 'usage'], 'bank_accounts_selectable_index');
        });
    }

    public function down(): void
    {
        /*
         * An MNO account has no representation in the old shape. Turning one
         * into a bank row would invent a bank it does not have; deleting it
         * would destroy a registered financial account. Neither is acceptable
         * in a financial system, so this refuses and says what to do.
         */
        $wallets = DB::table('bank_accounts')->where('account_type', 'mno')->count();

        if ($wallets > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$wallets} registered mobile money account(s) cannot be expressed as bank "
                .'accounts. Remove or migrate them deliberately first.',
            );
        }

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->dropIndex('bank_accounts_selectable_index');
            $table->unique('account_number');
        });

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->dropUnique('bank_accounts_physical_account_unique');
        });

        /* Most-derived first: physical_account_key reads account_number_key,
           which reads account_number. */
        DB::statement('ALTER TABLE bank_accounts DROP COLUMN physical_account_key');
        DB::statement('ALTER TABLE bank_accounts DROP COLUMN account_number_key');

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bank_id');
            $table->dropConstrainedForeignId('mobile_money_provider_id');
            $table->dropColumn(['account_type', 'usage']);
        });

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('account_name')
                ->constrained('branches')->nullOnDelete()->cascadeOnUpdate();
        });

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->index(['status', 'branch_id'], 'bank_accounts_status_branch_id_index');
        });
    }
};

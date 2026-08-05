<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exactly one System account, enforced by the database.
 *
 * The application already refuses to run automated work without one, and the
 * seeder creates one. Neither of those stops a SECOND one appearing — from a
 * hand-run SQL insert, a botched data migration, or two deploys racing. Two
 * system accounts would split automated postings between two identities and
 * quietly destroy the property the whole rule exists for: that the audit trail
 * can answer "what did the automation do".
 *
 * ## How the constraint works
 *
 * MySQL has no partial indexes, so this uses the standard equivalent: a STORED
 * generated column that is `1` for a system account and `NULL` for everybody
 * else, with a unique index over it. A unique index permits any number of
 * NULLs and exactly one `1` — which is precisely "at most one system account,
 * any number of humans".
 *
 * Generated rather than a real column so it cannot drift from `status`. There
 * is nothing to keep in sync and no way to set it wrongly; it is `status`
 * restated in a form the index can constrain.
 *
 * ## Why "at most one" and not "exactly one"
 *
 * A database constraint cannot require a row to exist. The other half — that
 * one is always present — is the seeder's job on a fresh install and
 * SystemActor's on every automated action, which refuses rather than
 * substituting a human. Between them the invariant holds from both directions.
 *
 * ## Existing databases
 *
 * Any duplicates are collapsed before the index is added, keeping the oldest —
 * the one whose id is already recorded against historical postings. Failing the
 * migration instead would leave an installation unable to deploy with no way
 * forward except manual surgery.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->collapseDuplicates();

        DB::statement(<<<'SQL'
            ALTER TABLE `users`
            ADD COLUMN `system_account` TINYINT(1)
                AS (CASE WHEN `status` = 'system' THEN 1 ELSE NULL END) STORED
        SQL);

        DB::statement('ALTER TABLE `users` ADD UNIQUE INDEX `users_system_account_unique` (`system_account`)');
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'system_account')) {
            DB::statement('ALTER TABLE `users` DROP INDEX `users_system_account_unique`');
            DB::statement('ALTER TABLE `users` DROP COLUMN `system_account`');
        }
    }

    /**
     * Demotes every system account but the oldest.
     *
     * Demoted rather than deleted: a duplicate may already be named on ledger
     * entries, and `journal_entries.created_by` is a foreign key. Suspending it
     * keeps the history readable while taking it out of the running — and it is
     * visible afterwards, so whoever inherits the installation can see that it
     * happened.
     */
    private function collapseDuplicates(): void
    {
        $keep = DB::table('users')->where('status', 'system')->orderBy('id')->value('id');

        if ($keep === null) {
            return;
        }

        DB::table('users')
            ->where('status', 'system')
            ->where('id', '!=', $keep)
            ->update(['status' => 'suspended']);
    }
};

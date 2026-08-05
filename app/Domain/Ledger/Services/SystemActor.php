<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Services;

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Auth\Enums\UserStatus;
use App\Exceptions\ConfigurationException;
use App\Models\User;

/**
 * The identity every automated process acts as. A permanent platform rule.
 *
 * Nightly jobs, interest accrual, advance consumption, reserve transfers,
 * background processing, webhooks, automatic accounting entries and every
 * future integration resolve their actor here. Nothing else is permitted:
 * not a Super Admin, not an Admin, not the currently authenticated user, not
 * null.
 *
 * ## Why it refuses instead of recovering
 *
 * An earlier version fell back to "the lowest-id Super Admin, or failing that
 * any user". That is the behaviour this class now exists to make impossible.
 * A fallback does not fail — it succeeds, quietly, and produces months of
 * ledger entries attributed to a real employee who did not make them. By the
 * time anybody notices, the audit trail cannot be repaired: there is no record
 * of which entries were the automation's and which were theirs.
 *
 * A missing System account, by contrast, is a deployment error. It is loud, it
 * is immediate, it names the fix, and nothing is written before it is resolved.
 * That is strictly the better failure.
 *
 * ## Why the duplicate check is here as well as in the schema
 *
 * The unique index makes two system accounts impossible on a healthy database.
 * This checks anyway, because the index could be absent on an installation that
 * skipped the migration, and the consequence — automated postings split across
 * two identities — is the exact harm the rule guards against. A constraint
 * worth having in the schema is worth asserting where it is relied upon.
 *
 * ## Caching
 *
 * Memoised per request. The account changes essentially never, and a nightly
 * job settling ten thousand advances should not issue ten thousand identical
 * lookups. Not cached ACROSS requests: a stale cache surviving the seeder that
 * fixed a missing account would turn a resolved deployment fault back into an
 * unresolved one.
 */
final class SystemActor
{
    private ?User $resolved = null;

    /**
     * @throws ConfigurationException when the platform has not been initialised
     */
    public function resolve(): User
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $accounts = User::query()
            ->where('status', UserStatus::System->value)
            ->orderBy('id')
            ->get();

        if ($accounts->isEmpty()) {
            throw ConfigurationException::systemUserMissing();
        }

        if ($accounts->count() > 1) {
            throw ConfigurationException::systemUserDuplicated($accounts->count());
        }

        return $this->resolved = $accounts->first();
    }

    /**
     * Whether the platform is initialised, without throwing.
     *
     * For the health endpoint and the console command — the two callers whose
     * whole job is to REPORT on readiness rather than to depend on it. Every
     * other caller must use `resolve()` and let the failure happen.
     */
    public function isInitialised(): bool
    {
        return User::query()->where('status', UserStatus::System->value)->count() === 1;
    }

    /**
     * The role a System account must hold.
     *
     * Exposed so the seeder and the guards that keep humans out of it read the
     * same constant, rather than each naming the role independently.
     */
    public function role(): RoleName
    {
        return RoleName::System;
    }
}

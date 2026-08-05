<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Auth\Enums\UserStatus;
use App\Domain\Ledger\Services\SystemActor;
use App\Exceptions\ConfigurationException;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\SystemUserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;

/**
 * `system:ensure-user` — the remediation path for an EXISTING installation.
 *
 * `migrate:fresh --seed` creates the System account on a new install. This
 * command is what an operator runs when a live database does not have one, so
 * it is the command that gets typed during an incident — which is exactly why
 * it needs to behave predictably when run twice, when run against a healthy
 * database, and when the platform is too broken for it to succeed.
 *
 * The test foundation seeds the account (tests/Pest.php), mirroring production.
 * Every test here that needs it absent therefore removes it first, which is
 * also the honest simulation: an installation missing the account is one where
 * something deleted it, not one where it never could have existed.
 */
beforeEach(function (): void {
    seedRbac();
});

/** Removes the System account, leaving the role and permissions in place. */
function forgetSystemAccount(): void
{
    User::query()->where('status', UserStatus::System->value)->forceDelete();
}

function systemAccountCount(): int
{
    return User::query()->where('status', UserStatus::System->value)->count();
}

describe('creating the account', function (): void {
    it('creates the System account when it is missing', function (): void {
        forgetSystemAccount();

        expect(systemAccountCount())->toBe(0);

        $this->artisan('system:ensure-user')
            ->expectsOutputToContain('System account created')
            ->assertSuccessful();

        expect(systemAccountCount())->toBe(1);
    });

    it('creates it with the identity the platform rule requires', function (): void {
        forgetSystemAccount();

        $this->artisan('system:ensure-user')->assertSuccessful();

        $system = User::query()->where('status', UserStatus::System->value)->sole();

        /*
         * The same four properties the seeder guarantees. Asserted here as
         * well because this command is a SECOND way the account can come into
         * existence, and a remediation path that produced a subtly different
         * account would be worse than no remediation path at all.
         */
        expect($system->role->name)->toBe(RoleName::System->value)
            ->and($system->email)->toBeNull()
            ->and($system->canAuthenticate())->toBeFalse()
            // Posted to no office: the automation acts for the institution.
            ->and($system->branch_id)->toBeNull()
            ->and($system->zone_id)->toBeNull();
    });

    it('creates an account that SystemActor can actually resolve', function (): void {
        forgetSystemAccount();

        $this->artisan('system:ensure-user')->assertSuccessful();

        /*
         * A fresh SystemActor, not the container's: the one bound during the
         * failed period may have memoised nothing, but proving the NEW state
         * resolves is the point. This is the method every automated process
         * calls, so it is the only meaningful definition of "fixed".
         */
        expect((new SystemActor)->resolve()->status)->toBe(UserStatus::System);
    });
});

describe('running it repeatedly', function (): void {
    it('is a no-op when the account already exists', function (): void {
        $before = User::query()->where('status', UserStatus::System->value)->sole();

        $this->artisan('system:ensure-user')
            ->expectsOutputToContain('already exists')
            ->assertSuccessful();

        $after = User::query()->where('status', UserStatus::System->value)->sole();

        // Same row, untouched — not deleted and recreated with a new id, which
        // would orphan every historical posting that names it.
        expect($after->getKey())->toBe($before->getKey())
            ->and($after->name)->toBe($before->name);
    });

    it('leaves the password alone when the account already exists', function (): void {
        $before = User::query()->where('status', UserStatus::System->value)->sole()->password;

        $this->artisan('system:ensure-user')->assertSuccessful();

        /*
         * The early return matters for more than speed. The seeder rotates the
         * password on every run, and a command an operator might run routinely
         * should not be quietly rewriting a credential — even one nobody holds.
         */
        expect(User::query()->where('status', UserStatus::System->value)->sole()->password)->toBe($before);
    });

    it('never produces a second account, however many times it runs', function (): void {
        forgetSystemAccount();

        $this->artisan('system:ensure-user')->assertSuccessful();
        $this->artisan('system:ensure-user')->assertSuccessful();
        $this->artisan('system:ensure-user')->assertSuccessful();

        expect(systemAccountCount())->toBe(1);
    });
});

describe('the database constraint behind it', function (): void {
    it('refuses a second system account at the schema level', function (): void {
        $roleId = Role::query()->where('name', RoleName::System->value)->value('id');

        /*
         * The command cannot create a duplicate, but a hand-run insert or a
         * botched data migration could try. The unique index over the generated
         * `system_account` column is what makes that impossible rather than
         * merely unlikely.
         */
        expect(fn () => User::query()->create([
            'name' => 'System Impostor',
            'phone' => 'SYSTEM-2',
            'email' => null,
            'password' => Hash::make('irrelevant'),
            'role_id' => $roleId,
            'status' => UserStatus::System,
        ]))->toThrow(QueryException::class);

        expect(systemAccountCount())->toBe(1);
    });

    it('still ensures exactly one after a duplicate attempt was refused', function (): void {
        $this->artisan('system:ensure-user')->assertSuccessful();

        expect(systemAccountCount())->toBe(1);
    });
});

describe('when the platform is too broken to fix', function (): void {
    it('fails with the configuration error rather than reporting success', function (): void {
        forgetSystemAccount();

        /*
         * The seeder returns early when the `system` role is absent, so without
         * this branch the command would run, create nothing, and exit 0 — the
         * silent no-op that is worse than a failure because the operator walks
         * away believing it is fixed.
         */
        Role::query()->where('name', RoleName::System->value)->delete();

        $this->artisan('system:ensure-user')
            ->expectsOutputToContain('System account has not been initialized. Run database seeders.')
            ->expectsOutputToContain('RoleSeeder')
            ->assertFailed();

        expect(systemAccountCount())->toBe(0);
    });

    it('surfaces the same message SystemActor throws', function (): void {
        forgetSystemAccount();

        /*
         * The command's error output and the exception every automated process
         * raises are the same sentence on purpose: an operator who sees it in a
         * 503 and an operator who sees it in a console are being told to do the
         * same thing.
         */
        expect(fn () => (new SystemActor)->resolve())
            ->toThrow(ConfigurationException::class, 'System account has not been initialized. Run database seeders.');
    });
});

describe('exit codes', function (): void {
    it('returns 0 when it created the account', function (): void {
        forgetSystemAccount();

        expect($this->artisan('system:ensure-user')->run())->toBe(0);
    });

    it('returns 0 when there was nothing to do', function (): void {
        expect($this->artisan('system:ensure-user')->run())->toBe(0);
    });

    it('returns a non-zero code when it could not fix the platform', function (): void {
        forgetSystemAccount();
        Role::query()->where('name', RoleName::System->value)->delete();

        // Non-zero rather than 1 specifically: what a deploy script or an
        // operator's `&&` chain needs is "did this fail", and pinning the exact
        // integer would make the test about Laravel's constant, not the
        // contract.
        expect($this->artisan('system:ensure-user')->run())->toBeGreaterThan(0);
    });
});

describe('the seeder it delegates to', function (): void {
    it('restores the account when run directly, keying on the fixed phone', function (): void {
        forgetSystemAccount();

        $this->seed(SystemUserSeeder::class);

        expect(systemAccountCount())->toBe(1)
            ->and(User::query()->where('phone', SystemUserSeeder::PHONE)->exists())->toBeTrue();
    });

    it('updates rather than inserting when the account is already there', function (): void {
        $before = User::query()->where('phone', SystemUserSeeder::PHONE)->sole()->getKey();

        $this->seed(SystemUserSeeder::class);

        expect(systemAccountCount())->toBe(1)
            ->and(User::query()->where('phone', SystemUserSeeder::PHONE)->sole()->getKey())->toBe($before);
    });
});

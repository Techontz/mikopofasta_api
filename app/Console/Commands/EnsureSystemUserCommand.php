<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ledger\Services\SystemActor;
use App\Exceptions\ConfigurationException;
use Database\Seeders\SystemUserSeeder;
use Illuminate\Console\Command;

/**
 * `system:ensure-user` — makes the platform's System account exist.
 *
 * Idempotent, and safe to run against a live installation: the seeder keys on a
 * fixed phone and updates rather than inserting, so running it twice produces
 * one account and running it against a healthy database changes nothing except
 * the password nobody holds.
 *
 * Exists so that initialising an EXISTING installation is a documented command
 * rather than "run the full seeder and hope nothing else is overwritten".
 * `migrate:fresh --seed` already creates the account on a new install.
 */
final class EnsureSystemUserCommand extends Command
{
    protected $signature = 'system:ensure-user';

    protected $description = 'Create the platform System account if it does not exist (idempotent)';

    public function handle(SystemActor $system): int
    {
        if ($system->isInitialised()) {
            $this->info('System account already exists. Nothing to do.');

            return self::SUCCESS;
        }

        $this->call('db:seed', ['--class' => SystemUserSeeder::class, '--force' => true]);

        /*
         * Verified by RESOLVING, not by asking again.
         *
         * Proving the account exists is not the same as proving the platform
         * can use it — resolve() is the method every automated process actually
         * calls, so exercising it here is what makes this command's success
         * mean something. Its message is also already written for an operator.
         */
        try {
            $actor = $system->resolve();
        } catch (ConfigurationException $e) {
            /*
             * The seeder returns early when the `system` role is missing, which
             * happens if RoleSeeder has never run. Saying so is the difference
             * between an operator running one more command and an operator
             * debugging a silent no-op.
             */
            $this->error($e->getMessage());
            $this->line('If the roles table is empty, run `php artisan db:seed --class=RoleSeeder` first.');

            return self::FAILURE;
        }

        $this->info(sprintf('System account created (#%d).', $actor->getKey()));

        return self::SUCCESS;
    }
}

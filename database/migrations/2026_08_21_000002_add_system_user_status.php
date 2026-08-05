<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Admits `system` to `users.status` — client Decision 4.
 *
 * The column is a database ENUM, so the PHP case alone is not enough. The
 * status is what makes the account non-login: LoginAction asks
 * `canAuthenticate()`, and System never can. That is a property of the data
 * rather than of somebody remembering to check a flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setStatuses(UserStatus::values());
    }

    public function down(): void
    {
        // Any system account must become an ordinary suspended one first, or
        // MySQL truncates it to the first enum value — silently making the
        // automation's identity look like a live account.
        DB::table('users')->where('status', 'system')->update(['status' => 'suspended']);

        $this->setStatuses(array_values(array_filter(
            UserStatus::values(),
            static fn (string $v): bool => $v !== 'system',
        )));
    }

    /** @param list<string> $values */
    private function setStatuses(array $values): void
    {
        $list = implode(',', array_map(static fn (string $v): string => "'".addslashes($v)."'", $values));

        DB::statement("ALTER TABLE `users` MODIFY COLUMN `status` ENUM({$list}) NOT NULL DEFAULT 'active'");
    }
};

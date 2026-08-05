<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Auth\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The automation's identity — client Decision 4.
 *
 *     "Create a dedicated non-login System User... Never use Super Admin for
 *      automated work."
 *
 * Used by nightly jobs, interest accrual, advance consumption, reserve
 * transfers, background processing and every automatic accounting posting.
 *
 * ## Why it cannot be logged into
 *
 * Three independent reasons, so removing any one of them does not open it:
 *
 *   1. `status = system`, and LoginAction refuses anything whose
 *      `canAuthenticate()` is false.
 *   2. Its password is a fresh 64-byte random string that is never recorded
 *      anywhere. Nobody knows it, including whoever ran this seeder.
 *   3. Its role holds no permissions, so an authenticated session as it could
 *      still do nothing.
 *
 * ## Why it has no email
 *
 * The password-reset broker is email-keyed. An account with no email cannot be
 * sent a reset link, which closes the obvious way of turning a non-login
 * account into a login one.
 */
final class SystemUserSeeder extends Seeder
{
    /** The phone column is unique and NOT NULL, so the account needs one. */
    public const string PHONE = 'SYSTEM';

    public function run(): void
    {
        $roleId = Role::query()->where('name', RoleName::System->value)->value('id');

        if ($roleId === null) {
            return;
        }

        User::query()->updateOrCreate(
            ['phone' => self::PHONE],
            [
                'name' => 'System',
                'email' => null,
                /*
                 * Rotated on every seed and never surfaced. `updateOrCreate`
                 * means re-seeding an existing installation quietly changes it,
                 * which is the desired behaviour for a credential nobody is
                 * supposed to hold.
                 */
                'password' => Hash::make(Str::random(64)),
                'role_id' => $roleId,
                /*
                 * Deliberately posted to no branch, zone or region. The
                 * automation acts for the institution, not for an office, and
                 * a branch-scoped system account would silently narrow what a
                 * nightly job could see.
                 */
                'branch_id' => null,
                'zone_id' => null,
                'region_id' => null,
                'status' => UserStatus::System,
            ],
        );
    }
}

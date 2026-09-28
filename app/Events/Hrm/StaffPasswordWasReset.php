<?php

namespace App\Events\Hrm;

use App\Models\Employee;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A staff member's password was reset to the configured default password.
 *
 * Carries no password (neither plaintext nor hash). Listeners that notify the staff member (SMS, e-mail) should tell
 * them to sign in with the default password issued by the administrator and change it — see
 * App\Services\Hrm\StaffPasswordReset for the extension point.
 */
class StaffPasswordWasReset
{
    use Dispatchable;

    public function __construct(
        public readonly Employee $employee,
        public readonly Employee $actor,
    ) {}
}

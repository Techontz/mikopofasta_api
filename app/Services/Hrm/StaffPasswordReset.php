<?php

namespace App\Services\Hrm;

use App\Events\Hrm\StaffPasswordWasReset;
use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resets a staff account to the single fixed default password from config('hrm.default_staff_password')
 * (env DEFAULT_STAFF_PASSWORD).
 *
 * The password is hashed by the Employee model's `hashed` cast, never returned, logged or audited, and every existing
 * Sanctum token of the staff member is revoked so open sessions must sign in again.
 *
 * Extension point: after a successful reset the {@see StaffPasswordWasReset} event is dispatched with the employee and
 * the acting administrator. To deliver a notice by SMS or e-mail, add a listener for that event (app/Listeners, which
 * Laravel discovers automatically) or a Notification sent from such a listener — the reset logic here does not change.
 */
class StaffPasswordReset
{
    public function isConfigured(): bool
    {
        $password = config('hrm.default_staff_password');

        return is_string($password) && $password !== '';
    }

    /**
     * @throws RuntimeException when the default staff password is not configured (nothing is changed).
     */
    public function reset(Employee $employee, Employee $actor): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('The default staff password is not configured.');
        }

        DB::transaction(function () use ($employee, $actor): void {
            $employee->forceFill(['password' => (string) config('hrm.default_staff_password')])->save();
            $employee->tokens()->delete();

            AuditLog::create([
                'company_id' => $employee->company_id,
                'employee_id' => $actor->id,
                'action' => 'Employee.password_reset',
                'auditable_type' => $employee->getMorphClass(),
                'auditable_id' => $employee->id,
                'before' => null,
                'after' => ['reset_to' => 'configured_default', 'tokens_revoked' => true],
                'ip_address' => request()?->ip(),
            ]);
        });

        StaffPasswordWasReset::dispatch($employee, $actor);
    }
}

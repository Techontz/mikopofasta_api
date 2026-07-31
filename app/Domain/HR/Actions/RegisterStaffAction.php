<?php

declare(strict_types=1);

namespace App\Domain\Hr\Actions;

use App\Domain\Auth\Actions\CreateUserAction;
use App\Domain\Hr\DTOs\RegisterStaffData;
use App\Domain\Hr\Enums\EmploymentStatus;
use App\Domain\Hr\Services\EmployeeNumberGenerator;
use App\Enums\AuditAction;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * `POST /staff` — §15.5.
 *
 * §11: "HR registers staff (users + staff_profiles created together)". Both in
 * one transaction, so there is never a user who cannot be paid or a payroll
 * line for somebody who cannot log in.
 *
 * The user is created through CreateUserAction rather than inline: password
 * hashing, the Spatie role sync and the user audit row are all its
 * responsibility, and duplicating them here would be a second, quietly
 * divergent way to provision an account.
 *
 * Zone and region come from the user's own assignment (§2.9 mirrors them onto
 * the profile) so that a zone manager's staff profile is scoped the same way
 * their access is.
 */
final class RegisterStaffAction
{
    public function __construct(
        private readonly CreateUserAction $createUser,
        private readonly EmployeeNumberGenerator $employeeNumbers,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(RegisterStaffData $data, User $actor): StaffProfile
    {
        return DB::transaction(function () use ($data, $actor): StaffProfile {
            $user = $this->createUser->handle($data->user, $actor);

            $profile = StaffProfile::query()->create([
                'user_id' => $user->getKey(),
                'employee_number' => $this->employeeNumbers->next(),
                'branch_id' => $user->branch_id,
                'zone_id' => $user->zone_id,
                'base_salary' => $data->baseSalary->toDecimalString(),
                'commission_eligible' => $data->commissionEligible,
                'payment_method' => $data->paymentMethod,
                'employment_status' => EmploymentStatus::Active,
                'hired_at' => $data->hiredAt,
            ]);

            if ($data->bankName !== null && $data->bankAccountNumber !== null) {
                $profile->bankDetail()->create([
                    'bank_name' => $data->bankName,
                    'account_number' => $data->bankAccountNumber,
                ]);
            }

            $this->audit->log(
                AuditAction::StaffRegistered,
                $profile,
                after: [
                    'employee_number' => $profile->employee_number,
                    'user_id' => $user->getKey(),
                    'role' => $user->role->name,
                    'base_salary' => $profile->base_salary,
                    'commission_eligible' => $profile->commission_eligible,
                ],
                actor: $actor,
            );

            return $profile->fresh(['user', 'branch', 'bankDetail']);
        });
    }
}

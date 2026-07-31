<?php

declare(strict_types=1);

namespace App\Domain\Hr\Actions;

use App\Domain\Hr\DTOs\StaffAllowanceData;
use App\Domain\Hr\Exceptions\StaffPayException;
use App\Enums\AuditAction;
use App\Models\PayrollRun;
use App\Models\StaffAllowance;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What an employee draws — HRM → Staff → Allowances.
 *
 * ## Why this exists
 *
 * `PayrollCalculator` held transport and airtime as class constants. Two
 * consequences followed: every branch employee drew exactly the same transport
 * figure regardless of where they travelled, and `AllowanceType::Bonus` — in
 * the enum, in the `allowances` table and on the frontend since the beginning —
 * was unreachable. Nothing in the system could award a bonus, which is the one
 * allowance a manager actually needs to decide.
 *
 * §10 of the HR document lists all three together: *Transport, Airtime, Bonus*.
 *
 * ## Recurring versus one-off
 *
 * A row with no period is recurring — drawn every month until stood down. A row
 * with one applies to that month alone, and a bonus is always the latter: a
 * bonus that repeated silently every month would be a salary increase nobody
 * approved.
 *
 * ## The period lock
 *
 * §16.1 — *"Salary haiwezi kubadilishwa baada ya approval"*. Granting an
 * allowance for a month whose payroll is already approved would change what
 * somebody is paid after the figures were agreed, so it is refused. The
 * allowance is still perfectly grantable for the following month.
 */
final class ManageStaffAllowanceAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function grant(StaffProfile $staff, StaffAllowanceData $data, User $actor): StaffAllowance
    {
        $this->guardPeriodOpen($data->period);
        $this->guardNoLiveDuplicate($staff, $data, null);

        return DB::transaction(function () use ($staff, $data, $actor): StaffAllowance {
            $allowance = StaffAllowance::query()->create([
                'staff_profile_id' => $staff->getKey(),
                'type' => $data->type,
                'amount' => $data->amount->toDecimalString(),
                'period' => $data->period,
                'reason' => $data->reason,
                'active' => true,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->log(
                AuditAction::StaffAllowanceGranted,
                $allowance,
                after: $this->snapshot($allowance),
                actor: $actor,
            );

            return $allowance;
        });
    }

    public function update(StaffAllowance $allowance, StaffAllowanceData $data, User $actor): StaffAllowance
    {
        $this->guardPeriodOpen($data->period);
        $this->guardPeriodOpen($allowance->period);
        $this->guardNoLiveDuplicate($allowance->staffProfile, $data, $allowance);

        return DB::transaction(function () use ($allowance, $data, $actor): StaffAllowance {
            $before = $this->snapshot($allowance);

            $allowance->update([
                'type' => $data->type,
                'amount' => $data->amount->toDecimalString(),
                'period' => $data->period,
                'reason' => $data->reason,
            ]);

            $this->audit->log(
                AuditAction::StaffAllowanceUpdated,
                $allowance,
                before: $before,
                after: $this->snapshot($allowance),
                actor: $actor,
            );

            return $allowance->fresh();
        });
    }

    /**
     * Stands an allowance down.
     *
     * Soft-deleted rather than removed, and only after being deactivated: past
     * payslips reference what was drawn, and "this person used to receive a
     * transport allowance" is exactly the kind of thing somebody asks about a
     * year later.
     */
    public function revoke(StaffAllowance $allowance, User $actor): void
    {
        $this->guardPeriodOpen($allowance->period);

        DB::transaction(function () use ($allowance, $actor): void {
            $this->audit->log(
                AuditAction::StaffAllowanceRevoked,
                $allowance,
                before: $this->snapshot($allowance),
                actor: $actor,
            );

            $allowance->update(['active' => false]);
            $allowance->delete();
        });
    }

    /**
     * The allowances a newly registered employee is enrolled on.
     *
     * @param list<array{type: \App\Domain\Hr\Enums\AllowanceType, amount: \App\Support\Money}> $entitlements
     */
    public function enrol(StaffProfile $staff, array $entitlements, User $actor): void
    {
        foreach ($entitlements as $entitlement) {
            StaffAllowance::query()->create([
                'staff_profile_id' => $staff->getKey(),
                'type' => $entitlement['type'],
                'amount' => $entitlement['amount']->toDecimalString(),
                'period' => null,
                'reason' => 'Standard entitlement on registration',
                'active' => true,
                'created_by' => $actor->getKey(),
            ]);
        }
    }

    /**
     * Refuses a change to a month whose payroll is already agreed.
     *
     * A recurring allowance (no period) is checked against the current month,
     * because that is the earliest run it could affect.
     */
    private function guardPeriodOpen(?string $period): void
    {
        $period ??= now()->format('Y-m');

        $run = PayrollRun::query()->where('period', $period)->first();

        if ($run !== null && ! $run->isEditable()) {
            throw StaffPayException::periodClosed($period, $run->status->label());
        }
    }

    /**
     * One live recurring allowance of each type per employee.
     *
     * The database enforces it too, through a partial unique index. Catching it
     * here means the person granting it is told which allowance already exists
     * rather than being handed an integrity-constraint violation.
     *
     * One-off rows are exempt: several bonuses in a month are legitimate, and
     * each was decided separately.
     */
    private function guardNoLiveDuplicate(
        StaffProfile $staff,
        StaffAllowanceData $data,
        ?StaffAllowance $except,
    ): void {
        if ($data->period !== null) {
            return;
        }

        $exists = StaffAllowance::query()
            ->where('staff_profile_id', $staff->getKey())
            ->where('type', $data->type)
            ->whereNull('period')
            ->where('active', true)
            ->when($except !== null, fn (Builder $q) => $q->whereKeyNot($except->getKey()))
            ->exists();

        if ($exists) {
            throw StaffPayException::allowanceAlreadyGranted($data->type->value);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(StaffAllowance $allowance): array
    {
        return $allowance->only(['staff_profile_id', 'type', 'amount', 'period', 'reason', 'active']);
    }
}

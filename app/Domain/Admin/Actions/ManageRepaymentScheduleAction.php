<?php

declare(strict_types=1);

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\DTOs\RepaymentScheduleData;
use App\Domain\Admin\Exceptions\SystemConfigurationException;
use App\Enums\AuditAction;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\RepaymentSchedule;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Repayment schedules — Settings → Repayment Schedules.
 *
 * Unlike interest formulas these are genuinely open: `frequency_days` is a
 * number the schedule generator divides by, not a branch it switches on, so a
 * fortnightly or quarterly schedule is a configuration change rather than a
 * code change.
 */
final class ManageRepaymentScheduleAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(RepaymentScheduleData $data, User $actor): RepaymentSchedule
    {
        $this->guardUnique($data, null);

        return DB::transaction(function () use ($data, $actor): RepaymentSchedule {
            $schedule = RepaymentSchedule::query()->create([
                'name' => $data->name,
                'code' => $data->code,
                'frequency_days' => $data->frequencyDays,
            ]);

            $this->audit->log(
                AuditAction::RepaymentScheduleCreated,
                $schedule,
                after: $schedule->only(['name', 'code', 'frequency_days']),
                actor: $actor,
            );

            return $schedule;
        });
    }

    public function update(RepaymentSchedule $schedule, RepaymentScheduleData $data, User $actor): RepaymentSchedule
    {
        $this->guardUnique($data, $schedule);
        $this->guardFrequencyChange($schedule, $data);

        return DB::transaction(function () use ($schedule, $data, $actor): RepaymentSchedule {
            $before = $schedule->only(['name', 'code', 'frequency_days']);

            $schedule->update([
                'name' => $data->name,
                'code' => $data->code,
                'frequency_days' => $data->frequencyDays,
            ]);

            $this->audit->log(
                AuditAction::RepaymentScheduleUpdated,
                $schedule,
                before: $before,
                after: $schedule->only(['name', 'code', 'frequency_days']),
                actor: $actor,
            );

            return $schedule->fresh();
        });
    }

    /**
     * Retires a schedule nothing is using.
     *
     * Soft-deleted, so a historical loan can still name what it ran on.
     */
    public function delete(RepaymentSchedule $schedule, User $actor): void
    {
        $loans = Loan::query()->where('repayment_schedule_id', $schedule->getKey())->count();

        if ($loans > 0) {
            throw SystemConfigurationException::scheduleHasLoans($schedule->name, $loans);
        }

        $product = LoanProduct::query()
            ->whereHas('repaymentSchedules', fn (Builder $q) => $q->whereKey($schedule->getKey()))
            ->first();

        if ($product !== null) {
            throw SystemConfigurationException::scheduleOnProduct($schedule->name, $product->name);
        }

        DB::transaction(function () use ($schedule, $actor): void {
            $this->audit->log(
                AuditAction::RepaymentScheduleDeleted,
                $schedule,
                before: $schedule->only(['name', 'code', 'frequency_days']),
                actor: $actor,
            );

            $schedule->delete();
        });
    }

    /**
     * Refuses a frequency change on a schedule loans are already running.
     *
     * The name and code are labels and may be corrected at any time. The
     * frequency is not: it is what generated every existing installment date on
     * every loan using this schedule. Changing it would leave those loans with
     * a schedule their own configuration no longer explains — the dates would
     * say fortnightly while the row said monthly — and nothing regenerates them.
     */
    private function guardFrequencyChange(RepaymentSchedule $schedule, RepaymentScheduleData $data): void
    {
        if ($schedule->frequency_days === $data->frequencyDays) {
            return;
        }

        $loans = Loan::query()->where('repayment_schedule_id', $schedule->getKey())->count();

        if ($loans > 0) {
            throw SystemConfigurationException::scheduleHasLoans($schedule->name, $loans);
        }
    }

    private function guardUnique(RepaymentScheduleData $data, ?RepaymentSchedule $except): void
    {
        $exists = fn (string $column, string $value): bool => RepaymentSchedule::query()
            ->when($except !== null, fn (Builder $q) => $q->whereKeyNot($except->getKey()))
            ->whereRaw("LOWER({$column}) = ?", [mb_strtolower($value)])
            ->exists();

        if ($exists('code', $data->code)) {
            throw SystemConfigurationException::duplicateCode($data->code);
        }

        if ($exists('name', $data->name)) {
            throw SystemConfigurationException::duplicateName($data->name);
        }
    }
}

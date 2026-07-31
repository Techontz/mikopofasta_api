<?php

declare(strict_types=1);

namespace App\Domain\Hr\Actions;

use App\Domain\Hr\Enums\PerformanceRating;
use App\Enums\AuditAction;
use App\Models\StaffPerformanceRecord;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * `POST /staff/performance` — §15.5, "Manager records targets/achieved/rating".
 *
 * Deliberately has no bearing on pay. §11 computes commission from branch
 * profit and payroll from base salary, and neither reads a performance rating;
 * a record here informs a conversation between a manager and an employee, not
 * a payslip. Wiring it into pay would be inventing an incentive scheme the
 * specification does not have.
 *
 * One record per staff member per period, so a manager revising a review
 * updates it rather than leaving two contradictory versions on file.
 */
final class RecordPerformanceAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param array<string, int|float> $targets
     * @param array<string, int|float> $achieved
     */
    public function handle(
        StaffProfile $staff,
        string $period,
        array $targets,
        array $achieved,
        ?PerformanceRating $rating,
        User $actor,
    ): StaffPerformanceRecord {
        return DB::transaction(function () use ($staff, $period, $targets, $achieved, $rating, $actor): StaffPerformanceRecord {
            $record = StaffPerformanceRecord::query()->updateOrCreate(
                ['staff_profile_id' => $staff->getKey(), 'period' => $period],
                [
                    'targets_json' => $targets,
                    'achieved_json' => $achieved,

                    // The rating is the manager's judgement, not a computed
                    // figure. The frontend derives one from a hit rate for its
                    // seed data, but it is stored as given — a manager who
                    // disagrees with the arithmetic is the point of having a
                    // manager.
                    'rating' => $rating,
                    'recorded_by' => $actor->getKey(),
                ],
            );

            $this->audit->log(
                AuditAction::PerformanceRecorded,
                $record,
                after: [
                    'staff_profile_id' => $staff->getKey(),
                    'period' => $period,
                    'rating' => $rating?->value,
                ],
                actor: $actor,
            );

            return $record->fresh(['staffProfile', 'recorder']);
        });
    }
}

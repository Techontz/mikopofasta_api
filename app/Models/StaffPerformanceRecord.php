<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Hr\Enums\PerformanceRating;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend spec §2.9 — `staff_performance_records`.
 *
 * Targets and achievements are free-form JSON because the metrics differ by
 * role; the frontend reviews loans disbursed, collection rate and new
 * customers for field staff.
 *
 * @property int $id
 * @property int $staff_profile_id
 * @property string $period
 * @property array<string, int|float> $targets_json
 * @property array<string, int|float> $achieved_json
 * @property PerformanceRating|null $rating
 * @property int $recorded_by
 */
class StaffPerformanceRecord extends Model
{
    /** @var list<string> */
    protected $fillable = ['staff_profile_id', 'period', 'targets_json', 'achieved_json', 'rating', 'recorded_by'];

    /**
     * @return BelongsTo<StaffProfile, $this>
     */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'targets_json' => 'array',
            'achieved_json' => 'array',
            'rating' => PerformanceRating::class,
        ];
    }
}

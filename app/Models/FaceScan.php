<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Customers\Enums\FaceScanStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One biometric verification event — see the 2026_08_14 migration.
 *
 * Immutable by intention. Nothing in the application updates a scan's
 * measurements after the fact; the only column that ever changes is
 * `is_active`, when a later scan supersedes this one. An audit record that
 * can be edited is not an audit record.
 *
 * @property int $id
 * @property int $customer_id
 * @property FaceScanStatus $status
 * @property int $quality_score
 * @property int $brightness_score
 * @property int $blur_score
 * @property int $distance_score
 * @property int $centering_score
 * @property int $eyes_open_score
 * @property string $scanner_version
 * @property bool $liveness_passed
 * @property bool $pose_sequence_completed
 * @property array<string, bool> $checks
 * @property string|null $capture_device
 * @property string|null $capture_resolution
 * @property int|null $capture_duration_ms
 * @property string $photo_path
 * @property string|null $reason
 * @property int|null $scanned_by
 * @property CarbonImmutable $scanned_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property bool $is_active
 */
class FaceScan extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id', 'status',
        'quality_score', 'brightness_score', 'blur_score',
        'distance_score', 'centering_score', 'eyes_open_score',
        'scanner_version', 'liveness_passed', 'pose_sequence_completed', 'checks',
        'capture_device', 'capture_resolution', 'capture_duration_ms',
        'photo_path', 'reason',
        'scanned_by', 'scanned_at', 'ip_address', 'user_agent', 'is_active',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The operator who ran the scan. Nullable because a user may be deleted
     * long after the scan they took, and losing the scan with them would be
     * the wrong trade.
     *
     * @return BelongsTo<User, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }

    /**
     * @param Builder<FaceScan> $query
     * @return Builder<FaceScan>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FaceScanStatus::class,
            'checks' => 'array',
            'liveness_passed' => 'boolean',
            'pose_sequence_completed' => 'boolean',
            'is_active' => 'boolean',
            'scanned_at' => 'datetime',
            'quality_score' => 'integer',
            'brightness_score' => 'integer',
            'blur_score' => 'integer',
            'distance_score' => 'integer',
            'centering_score' => 'integer',
            'eyes_open_score' => 'integer',
            'capture_duration_ms' => 'integer',
        ];
    }
}

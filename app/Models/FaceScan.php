<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One liveness scan of a customer. History is kept; only `is_active` moves to the latest scan.
 */
class FaceScan extends Model
{
    /** Private disk holding the capture. */
    public const DISK = 'local';

    /**
     * The eleven checks every scan must report.
     *
     * @var list<string>
     */
    public const CHECKS = ['oneFaceDetected', 'eyesOpen', 'centered', 'correctDistance', 'goodLighting', 'sharpImage', 'poseStraight', 'poseLeft', 'poseRight', 'poseUp', 'poseDown'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checks' => 'array',
            'liveness_passed' => 'boolean',
            'pose_sequence_completed' => 'boolean',
            'is_active' => 'boolean',
            'scanned_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scanner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'scanned_by');
    }
}

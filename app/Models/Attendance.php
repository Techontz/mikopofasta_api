<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily check-in / check-out of an employee (handwritten HR note: "Attendance connected to HR's system").
 */
class Attendance extends Model
{
    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'present' => 'Present',
        'late' => 'Late',
        'absent' => 'Absent',
        'leave' => 'Leave',
    ];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recorded_by');
    }

    /**
     * Worked hours between check-in and check-out.
     */
    public function hoursWorked(): float
    {
        if ($this->check_in === null || $this->check_out === null) {
            return 0.0;
        }

        $seconds = strtotime((string) $this->check_out) - strtotime((string) $this->check_in);

        return max(0.0, round($seconds / 3600, 2));
    }
}

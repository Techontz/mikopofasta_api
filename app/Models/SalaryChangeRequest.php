<?php

namespace App\Models;

use Database\Factories\SalaryChangeRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Spec §32: a proposed change of an employee's existing salary. HR proposes; Finance approves, or Admin when the salary is the
 * proposer's own or belongs to an HR user. The employee's salary record changes only on approval.
 */
class SalaryChangeRequest extends Model
{
    /** @use HasFactory<SalaryChangeRequestFactory> */
    use HasFactory;

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STAGE_FINANCE = 'finance';

    public const STAGE_ADMIN = 'admin';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_values' => 'array',
            'proposed_values' => 'array',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rejected_by');
    }
}

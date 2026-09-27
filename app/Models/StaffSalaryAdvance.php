<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffSalaryAdvance extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'recovered_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'finance_approved_at' => 'datetime',
            'disbursed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'completed_at' => 'datetime',
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

    /**
     * Review stage approver (HR, or Admin for an HR user's own request).
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    public function financeApprover(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'finance_approved_by');
    }

    public function disburser(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'disbursed_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rejected_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(StaffSalaryAdvanceCategory::class, 'staff_salary_advance_category_id');
    }

    /**
     * Part of a disbursed advance not yet recovered from salary.
     */
    public function outstandingAmount(): float
    {
        return max(0, round((float) $this->amount - (float) $this->recovered_amount, 2));
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffLoan extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_applied' => 'decimal:2',
            'amount_approved' => 'decimal:2',
            'total_payable' => 'decimal:2',
            'restoration' => 'decimal:2',
            'fee' => 'decimal:2',
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
        return $this->belongsTo(StaffLoanCategory::class, 'staff_loan_category_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(StaffLoanPayment::class);
    }

    /**
     * Amount repaid so far (uses a loaded `payments_sum_amount` when available).
     */
    public function paidAmount(): float
    {
        return (float) ($this->payments_sum_amount ?? $this->payments()->sum('amount'));
    }

    public function remainingAmount(): float
    {
        return max(0, round((float) $this->total_payable - $this->paidAmount(), 2));
    }
}

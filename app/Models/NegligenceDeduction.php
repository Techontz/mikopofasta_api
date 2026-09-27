<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Staff negligence / loss deduction (spec §23, §57). HR creates it, Finance approves it and payroll recovers it from the
 * employee's COMMISSION only — never from salary and never as a Staff Fund repayment. What the commission of a period cannot
 * cover stays outstanding and is carried forward to the next commission until it is fully recovered.
 *
 * pending → approved → recovering → recovered, or pending → rejected.
 */
class NegligenceDeduction extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_RECOVERING = 'recovering';

    public const STATUS_RECOVERED = 'recovered';

    public const STATUS_REJECTED = 'rejected';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'recovered_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /**
     * Approved deductions that still have an outstanding balance to recover from commission.
     *
     * @param  Builder<NegligenceDeduction>  $query
     */
    public function scopeRecoverable(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_RECOVERING])->whereColumn('recovered_amount', '<', 'amount');
    }

    public function outstandingAmount(): float
    {
        return round(max(0, (float) $this->amount - (float) $this->recovered_amount), 2);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rejected_by');
    }

    public function recoveries(): HasMany
    {
        return $this->hasMany(NegligenceRecovery::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Staff allowance (spec §24, §58): a payroll entitlement, never a cash account. HR creates it with a reason for a payroll period
 * (pending) → Finance approves it (approved: "Approved / Awaiting Payroll") → the payroll that pays it consumes it (paid).
 * Allowances created before Finance approval existed are recurring approved allowances (`active`) until HR stops them.
 */
class StaffAllowance extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    public const STATUS_REJECTED = 'rejected';

    /** Legacy recurring allowance, approved before Finance approval existed; added to every payroll until stopped. */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_STOPPED = 'stopped';

    /**
     * @var list<string>
     */
    public const REASONS = ['overtime', 'leave', 'transport', 'other'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payroll_period' => 'date',
            'recurring' => 'boolean',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * Allowances a payroll of the month may pay: recurring approved allowances, and Finance-approved allowances of this or an
     * earlier payroll period not yet taken by another payroll (an allowance approved after its month's payroll carries forward).
     *
     * @param  Builder<StaffAllowance>  $query
     */
    public function scopePayableIn(Builder $query, CarbonImmutable $month): void
    {
        $query->where(fn (Builder $inner) => $inner
            ->where('status', self::STATUS_ACTIVE)
            ->orWhere(fn (Builder $approved) => $approved
                ->where('status', self::STATUS_APPROVED)
                ->whereNull('payroll_run_id')
                ->whereDate('payroll_period', '<=', $month->startOfMonth()->toDateString())));
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pending Finance Approval',
            self::STATUS_APPROVED => 'Approved / Awaiting Payroll',
            self::STATUS_PAID => 'Paid',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_ACTIVE => 'Approved (recurring)',
            self::STATUS_STOPPED => 'Stopped',
            default => (string) $this->status,
        };
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

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }
}

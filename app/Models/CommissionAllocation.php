<?php

namespace App\Models;

use App\Services\Hrm\CommissionPayments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Commission earned by one employee for a closed accounting period (STAFF COMMISSION §7–8).
 * kind = branch_staff (share of a branch pool) or zone_manager (override on the zone's pools).
 *
 * Payment (spec §21 / §22 / §49, {@see CommissionPayments}): calculated → awaiting_request (HR finalised) →
 * requested (HR payment request) → finance_approved → paid. `payroll` = LEGACY: the allocation was carried by a payroll run
 * before commission got its own payment flow; that run recognised and paid it.
 */
class CommissionAllocation extends Model
{
    public const KIND_BRANCH_STAFF = 'branch_staff';

    public const KIND_ZONE_MANAGER = 'zone_manager';

    public const STATUS_CALCULATED = 'calculated';

    public const STATUS_AWAITING_REQUEST = 'awaiting_request';

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_FINANCE_APPROVED = 'finance_approved';

    public const STATUS_PAID = 'paid';

    /** LEGACY: carried by a payroll run (before the commission payment flow). */
    public const STATUS_PAYROLL = 'payroll';

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_CALCULATED => 'Calculated',
        self::STATUS_AWAITING_REQUEST => 'Awaiting Payment Request',
        self::STATUS_REQUESTED => 'Payment Requested',
        self::STATUS_FINANCE_APPROVED => 'Finance Approved',
        self::STATUS_PAID => 'Paid',
        self::STATUS_PAYROLL => 'In Payroll (legacy)',
    ];

    /** Statuses after HR finalised the figures: the period's commission can no longer be recalculated. */
    public const FINALISED_STATUSES = [self::STATUS_AWAITING_REQUEST, self::STATUS_REQUESTED, self::STATUS_FINANCE_APPROVED, self::STATUS_PAID];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'distributable_profit' => 'decimal:2',
            'pool_percent' => 'decimal:2',
            'pool_amount' => 'decimal:2',
            'base_salary' => 'decimal:2',
            'total_salary' => 'decimal:2',
            'share_percent' => 'decimal:4',
            'amount' => 'decimal:2',
            'negligence_deduction' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'finalized_at' => 'datetime',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'paid_at' => 'datetime',
            'paid_on' => 'date',
        ];
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->payment_status] ?? (string) $this->payment_status;
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'finalized_by');
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

    public function payer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'paid_by');
    }

    public function payingBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'paying_branch_id');
    }

    public function paymentJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'payment_journal_entry_id');
    }

    public function negligenceRecoveries(): HasMany
    {
        return $this->hasMany(NegligenceRecovery::class);
    }
}

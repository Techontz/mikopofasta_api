<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Staff benefit claim: a payment of an employee's staff fund benefit out of the single STAFF FUND A/C (spec §27 / §49).
 *
 * prepared (HR, with the recorded benefit entitlement) → finance_review → approved → paid, or rejected before payment. Only
 * the payment moves money. Rows recorded before this workflow were paid immediately and are migrated as paid.
 */
class StaffFundWithdrawal extends Model
{
    use Auditable;

    public const STATUS_PREPARED = 'prepared';

    public const STATUS_FINANCE_REVIEW = 'finance_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    public const STATUS_REJECTED = 'rejected';

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_PREPARED => 'Prepared by HR',
        self::STATUS_FINANCE_REVIEW => 'Finance Review',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_PAID => 'Paid',
        self::STATUS_REJECTED => 'Rejected',
    ];

    /** Claims still reserving part of the employee's benefit entitlement (not paid, not rejected). */
    public const OPEN_STATUSES = [self::STATUS_PREPARED, self::STATUS_FINANCE_REVIEW, self::STATUS_APPROVED];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'entitlement' => 'decimal:2',
            'prepared_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recorded_by');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'prepared_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewed_by');
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

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}

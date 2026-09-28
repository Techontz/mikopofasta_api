<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\ReversalRequests;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Maker/checker for money reversals ({@see ReversalRequests}): Finance requests the reversal of a loan repayment, a loan
 * disbursement, a direct penalty payment or a salary advance deposit as PENDING — nothing posted — and it is posted only when another authorised user
 * (Finance, Admin or Super Admin, permission reversals.approve) approves it; a rejection keeps the row with the reason.
 */
class ReversalRequest extends Model
{
    use Auditable;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const REPAYMENT = 'loan_repayment';

    public const DISBURSEMENT = 'loan_disbursement';

    public const PENALTY_PAYMENT = 'penalty_payment';

    public const SALARY_ADVANCE_PAYMENT = 'salary_advance_payment';

    /**
     * Type => label.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        self::REPAYMENT => 'Loan Repayment',
        self::DISBURSEMENT => 'Loan Disbursement',
        self::PENALTY_PAYMENT => 'Penalty Payment',
        self::SALARY_ADVANCE_PAYMENT => 'Salary Advance Deposit',
    ];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}

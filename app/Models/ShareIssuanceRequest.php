<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\Shares\ShareIssuance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A requested PAID share issuance awaiting approval (C6 maker/checker, {@see ShareIssuance::requestIssuance()}). While pending
 * nothing exists in the register or the ledger: no capital contribution, no journal, no share transaction. A different
 * authorised user approves it (the contribution, its journal and the share transaction are posted, dated the approval date) or
 * rejects it (kept as REJECTED with the reason).
 */
class ShareIssuanceRequest extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * Private disk holding the payment receipt / supporting document until approval moves it onto the contribution.
     */
    public const DISK = 'local';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shares' => 'integer',
            'price_per_share' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'issue_date' => 'date',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function shareHolder(): BelongsTo
    {
        return $this->belongsTo(ShareHolder::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
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

    public function shareTransaction(): BelongsTo
    {
        return $this->belongsTo(ShareTransaction::class);
    }

    public function capital(): BelongsTo
    {
        return $this->belongsTo(Capital::class);
    }
}

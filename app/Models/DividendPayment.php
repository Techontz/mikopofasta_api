<?php

namespace App\Models;

use App\Enums\Account;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One (full or partial) payment of a shareholder's dividend entitlement: Dr DIVIDEND ACCOUNT / Cr COMPANY ACCOUNT
 * (CASH) or the selected bank account (BANK). Rows are never deleted; a reversal posts an opposite journal entry and
 * marks the payment reversed, which restores the allocation balance.
 */
class DividendPayment extends Model
{
    use Auditable;

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(DividendAllocation::class, 'dividend_allocation_id');
    }

    /**
     * The PAY ALL OUTSTANDING batch that posted this payment, if any.
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(DividendPaymentBatch::class, 'dividend_payment_batch_id');
    }

    public function shareHolder(): BelongsTo
    {
        return $this->belongsTo(ShareHolder::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'paid_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reversed_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    /**
     * "COMPANY ACCOUNT" or the bank account name.
     */
    public function accountLabel(): string
    {
        return $this->pay_method === 'BANK'
            ? ($this->bankAccount?->name ?? Account::Bank->label())
            : Account::Company->label();
    }
}

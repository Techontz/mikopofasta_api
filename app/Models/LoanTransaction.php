<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LoanTransaction extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount' => 'decimal:2',
            'principal' => 'decimal:2',
            'penalty' => 'decimal:2',
            'interest' => 'decimal:2',
            'insurance' => 'decimal:2',
            'reserve' => 'decimal:2',
            'reversed_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The journal entry this transaction posted.
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reversed_by');
    }

    public function paymentAllocation(): HasOne
    {
        return $this->hasOne(PaymentAllocation::class);
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }
}

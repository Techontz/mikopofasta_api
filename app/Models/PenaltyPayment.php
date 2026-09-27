<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PenaltyPayment extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'amount' => 'decimal:2',
            'reversed_at' => 'datetime',
        ];
    }

    public function penalty(): BelongsTo
    {
        return $this->belongsTo(Penalty::class);
    }

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

    /**
     * Payments still standing (a reversed direct penalty payment is kept with its trace, never deleted).
     *
     * @param  Builder<PenaltyPayment>  $query
     */
    public function scopeStanding(Builder $query): void
    {
        $query->whereNull('reversed_at');
    }

    /**
     * A payment made on the penalty itself (Penalty → pay), not the penalty portion of a loan repayment.
     */
    public function isDirect(): bool
    {
        return $this->loan_transaction_id === null;
    }
}
